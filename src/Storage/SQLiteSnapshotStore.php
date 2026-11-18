<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Storage;

use HElturkey\WebTrawler\Adaptive\ElementSnapshot;
use HElturkey\WebTrawler\Contracts\SnapshotStore;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * SQLite-backed snapshot store for several worker processes on one machine.
 *
 * Each write is a single UPSERT statement, so concurrent writers never rewrite a whole file the way the
 * JSON store does: different keys never conflict, and the same key is last-writer-wins. The database runs in
 * WAL mode with a busy timeout, so readers do not block the writer and a writer waits for another writer
 * instead of failing. The connection is opened lazily and reopened after a fork, because a PDO handle must
 * not be shared between processes.
 *
 * SQLite uses file locks, so do not put the database on a network file system.
 */
final class SQLiteSnapshotStore implements SnapshotStore
{
    private ?PDO $pdo = null;
    private ?int $ownerPid = null;

    /** @param int $busyTimeoutMs How long a statement waits for a lock held by another process. */
    public function __construct(private readonly string $path, private readonly int $busyTimeoutMs = 5000)
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('SQLiteSnapshotStore needs the pdo_sqlite PHP extension.');
        }
        if ($busyTimeoutMs < 0) {
            throw new \InvalidArgumentException('The busy timeout cannot be negative.');
        }
        if ($path !== ':memory:') {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Unable to create snapshot directory: %s', $directory));
            }
        }
    }

    public function put(string $key, ElementSnapshot $snapshot): void
    {
        $this->validateKey($key);
        try {
            $json = json_encode($snapshot->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Snapshot cannot be encoded as JSON.', previous: $exception);
        }

        $this->statement(
            'INSERT INTO snapshots (snapshot_key, data, updated_at) VALUES (:key, :data, :updated_at) '
            . 'ON CONFLICT(snapshot_key) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at',
            ['key' => $key, 'data' => $json, 'updated_at' => time()],
        );
    }

    public function get(string $key): ?ElementSnapshot
    {
        $this->validateKey($key);
        $row = $this->statement('SELECT data FROM snapshots WHERE snapshot_key = :key', ['key' => $key])->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->decode((string) $row['data']) : null;
    }

    public function all(): array
    {
        $result = [];
        $rows = $this->statement('SELECT snapshot_key, data FROM snapshots ORDER BY snapshot_key', [])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $snapshot = $this->decode((string) $row['data']);
            if ($snapshot !== null) {
                $result[(string) $row['snapshot_key']] = $snapshot;
            }
        }

        return $result;
    }

    public function remove(string $key): void
    {
        $this->validateKey($key);
        $this->statement('DELETE FROM snapshots WHERE snapshot_key = :key', ['key' => $key]);
    }

    /** Close the connection; the next call opens a new one. */
    public function close(): void
    {
        $this->pdo = null;
        $this->ownerPid = null;
    }

    private function decode(string $json): ?ElementSnapshot
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Snapshot store contains invalid JSON.', previous: $exception);
        }

        return is_array($data) ? ElementSnapshot::fromArray($data) : null;
    }

    /** @param array<string, scalar> $parameters */
    private function statement(string $sql, array $parameters): \PDOStatement
    {
        $statement = $this->connection()->prepare($sql);
        $statement->execute($parameters);

        return $statement;
    }

    private function connection(): PDO
    {
        $pid = getmypid();
        if ($this->pdo !== null && $this->ownerPid === $pid) {
            return $this->pdo;
        }

        // Never reuse a handle inherited through fork(): drop it without closing the parent's connection.
        $pdo = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = ' . $this->busyTimeoutMs);
        $this->retryWhileBusy(static function () use ($pdo): void {
            $pdo->exec('PRAGMA journal_mode = WAL');
        });
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $this->retryWhileBusy(static function () use ($pdo): void {
            $pdo->exec('CREATE TABLE IF NOT EXISTS snapshots (snapshot_key TEXT NOT NULL PRIMARY KEY, data TEXT NOT NULL, updated_at INTEGER NOT NULL) WITHOUT ROWID');
        });

        $this->pdo = $pdo;
        $this->ownerPid = $pid === false ? null : $pid;

        return $pdo;
    }

    /**
     * Switching to WAL for the first time, or creating the table, can report "database is locked" at once
     * when several processes start together (the busy handler is not consulted for every such case).
     *
     * @param callable(): void $operation
     */
    private function retryWhileBusy(callable $operation): void
    {
        $deadline = hrtime(true) + max(1, $this->busyTimeoutMs) * 1_000_000;
        while (true) {
            try {
                $operation();

                return;
            } catch (PDOException $exception) {
                $busy = stripos($exception->getMessage(), 'locked') !== false || stripos($exception->getMessage(), 'busy') !== false;
                if (!$busy || hrtime(true) >= $deadline) {
                    throw $exception;
                }
                usleep(random_int(2_000, 20_000));
            }
        }
    }

    private function validateKey(string $key): void
    {
        if ($key === '' || strlen($key) > 190) {
            throw new \InvalidArgumentException('Snapshot keys must contain 1 to 190 bytes.');
        }
    }
}
