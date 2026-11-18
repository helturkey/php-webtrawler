<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Storage;

use HElturkey\WebTrawler\Contracts\BloomFilter as BloomFilterContract;
use HElturkey\WebTrawler\Contracts\BatchCrawlDeduplicator;
use HElturkey\WebTrawler\Support\BloomFilter;
use PDO;
use PDOException;
use RuntimeException;

/**
 * SQLite-backed URL and exact-content deduplication for a single machine.
 *
 * SQLite UNIQUE constraints remain authoritative. The in-process Bloom filter only avoids SELECTs for
 * values that are definitely absent; positive results are always confirmed by SQLite before a URL is
 * considered a duplicate. The store is scoped per crawl so a later crawl can fetch the same URLs again.
 */
final class SQLiteCrawlDeduplicator implements BatchCrawlDeduplicator
{
    private ?PDO $pdo = null;
    private ?int $ownerPid = null;
    private BloomFilterContract $bloomFilter;

    public function __construct(
        private readonly string $path,
        int $expectedUrls = 100_000,
        float $falsePositiveProbability = 0.01,
        private readonly int $busyTimeoutMs = 5_000,
        ?BloomFilterContract $bloomFilter = null,
    ) {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('SQLiteCrawlDeduplicator needs the pdo_sqlite PHP extension.');
        }
        if ($busyTimeoutMs < 0) {
            throw new \InvalidArgumentException('The SQLite busy timeout cannot be negative.');
        }
        if ($path !== ':memory:') {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Unable to create crawl database directory: %s', $directory));
            }
        }

        $this->bloomFilter = $bloomFilter ?? new BloomFilter($expectedUrls, $falsePositiveProbability);
        $this->connection();
    }

    public function markUrlSeen(string $scope, string $fingerprint): bool
    {
        $this->validateScope($scope);
        $this->validateHash($fingerprint, 'URL fingerprint');
        $filterKey = $scope . "\0" . $fingerprint;

        // A positive Bloom result may be a false positive, so it must be checked against SQLite.
        if ($this->bloomFilter->mightContain($filterKey)) {
            $existing = $this->statement(
                'SELECT 1 FROM crawl_url_fingerprints WHERE scope = :scope AND fingerprint = :fingerprint LIMIT 1',
                ['scope' => $scope, 'fingerprint' => $fingerprint],
            )->fetchColumn();
            if ($existing !== false) {
                return true;
            }
        }

        // The UNIQUE key also handles races between workers or a stale/undersized Bloom filter.
        $insert = $this->statement(
            'INSERT OR IGNORE INTO crawl_url_fingerprints (scope, fingerprint, created_at) VALUES (:scope, :fingerprint, :created_at)',
            ['scope' => $scope, 'fingerprint' => $fingerprint, 'created_at' => time()],
        );
        $isDuplicate = $insert->rowCount() === 0;
        // Also cache a duplicate discovered via the UNIQUE constraint (for example, another worker
        // inserted it after this process rebuilt its Bloom filter).
        $this->bloomFilter->add($filterKey);

        return $isDuplicate;
    }

    /**
     * Record many fingerprints in a single transaction: one commit instead of one per URL. The UNIQUE key is the
     * only authority here (INSERT OR IGNORE), so the Bloom filter is merely kept up to date.
     */
    public function markUrlsSeenBatch(string $scope, array $fingerprints): array
    {
        $this->validateScope($scope);
        if ($fingerprints === []) {
            return [];
        }
        foreach ($fingerprints as $fingerprint) {
            $this->validateHash($fingerprint, 'URL fingerprint');
        }

        $connection = $this->connection();
        $insert = $connection->prepare('INSERT OR IGNORE INTO crawl_url_fingerprints (scope, fingerprint, created_at) VALUES (:scope, :fingerprint, :created_at)');
        $now = time();
        $duplicates = [];

        $connection->beginTransaction();
        try {
            foreach ($fingerprints as $fingerprint) {
                $insert->execute(['scope' => $scope, 'fingerprint' => $fingerprint, 'created_at' => $now]);
                $duplicates[] = $insert->rowCount() === 0;
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
        foreach ($fingerprints as $fingerprint) {
            $this->bloomFilter->add($scope . "\0" . $fingerprint);
        }

        return $duplicates;
    }

    public function recordContent(string $scope, string $url, string $contentHash, int $sizeBytes): bool
    {
        $this->validateScope($scope);
        $this->validateHash($contentHash, 'Content hash');
        if ($url === '' || $sizeBytes < 0) {
            throw new \InvalidArgumentException('Content URL must not be empty and byte size cannot be negative.');
        }
        // Keep credentials, query tokens and raw paths out of the database. Only the URL fingerprint is stored.
        $urlKey = hash('sha256', $url);

        $insert = $this->statement(
            'INSERT OR IGNORE INTO crawl_content_fingerprints (scope, content_hash, first_url_key, size_bytes, created_at) '
            . 'VALUES (:scope, :content_hash, :url, :size_bytes, :created_at)',
            ['scope' => $scope, 'content_hash' => $contentHash, 'url' => $urlKey, 'size_bytes' => $sizeBytes, 'created_at' => time()],
        );
        if ($insert->rowCount() === 1) {
            return false;
        }

        $firstUrlKey = $this->statement(
            'SELECT first_url_key FROM crawl_content_fingerprints WHERE scope = :scope AND content_hash = :content_hash LIMIT 1',
            ['scope' => $scope, 'content_hash' => $contentHash],
        )->fetchColumn();

        return is_string($firstUrlKey) && $firstUrlKey !== $urlKey;
    }

    public function clearScope(string $scope): void
    {
        $this->validateScope($scope);
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $this->statement('DELETE FROM crawl_content_fingerprints WHERE scope = :scope', ['scope' => $scope]);
            $this->statement('DELETE FROM crawl_url_fingerprints WHERE scope = :scope', ['scope' => $scope]);
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
        // Rebuild after deletion so long-lived workers do not accumulate stale bits across crawl scopes.
        $this->rebuildBloomFilter($connection);
    }

    /**
     * Delete URL and content records older than $maxAgeSeconds, in every scope, and return the number of rows
     * removed. Scopes are normally cleared when a crawl finishes, but a crawl that was killed (SIGKILL, power
     * loss, OOM) leaves its rows behind; run this from a scheduled job to keep the database from growing forever.
     * Never purge a window shorter than the longest crawl you run, or a resumable crawl may fetch pages again.
     */
    public function purgeOlderThan(int $maxAgeSeconds): int
    {
        if ($maxAgeSeconds < 0) {
            throw new \InvalidArgumentException('The maximum record age cannot be negative.');
        }
        $cutoff = time() - $maxAgeSeconds;
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $content = $this->statement('DELETE FROM crawl_content_fingerprints WHERE created_at < :cutoff', ['cutoff' => $cutoff]);
            $urls = $this->statement('DELETE FROM crawl_url_fingerprints WHERE created_at < :cutoff', ['cutoff' => $cutoff]);
            $removed = $content->rowCount() + $urls->rowCount();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
        if ($removed > 0) {
            $this->rebuildBloomFilter($connection);
        }

        return $removed;
    }

    public function close(): void
    {
        $this->pdo = null;
        $this->ownerPid = null;
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
            $pdo->exec('CREATE TABLE IF NOT EXISTS crawl_url_fingerprints ('
                . 'scope TEXT NOT NULL, fingerprint TEXT NOT NULL, created_at INTEGER NOT NULL, '
                . 'PRIMARY KEY (scope, fingerprint)) WITHOUT ROWID');
            $pdo->exec('CREATE TABLE IF NOT EXISTS crawl_content_fingerprints ('
                . 'scope TEXT NOT NULL, content_hash TEXT NOT NULL, first_url_key TEXT NOT NULL, '
                . 'size_bytes INTEGER NOT NULL, created_at INTEGER NOT NULL, '
                . 'PRIMARY KEY (scope, content_hash)) WITHOUT ROWID');
        });

        $this->rebuildBloomFilter($pdo);

        $this->pdo = $pdo;
        $this->ownerPid = $pid === false ? null : $pid;

        return $pdo;
    }

    /**
     * Rebuilding is a sequential scan with one row at a time. If another worker inserts after this scan,
     * SQLite's UNIQUE constraint still protects correctness when that worker is reached.
     */
    private function rebuildBloomFilter(PDO $pdo): void
    {
        $this->bloomFilter->clear();
        $rows = $pdo->query('SELECT scope, fingerprint FROM crawl_url_fingerprints');
        if (!$rows instanceof \PDOStatement) {
            throw new RuntimeException('Unable to rebuild the crawl URL Bloom filter from SQLite.');
        }
        while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
            $this->bloomFilter->add((string) $row['scope'] . "\0" . (string) $row['fingerprint']);
        }
    }

    /** @param callable(): void $operation */
    private function retryWhileBusy(callable $operation): void
    {
        $deadline = hrtime(true) + max(1, $this->busyTimeoutMs) * 1_000_000;
        while (true) {
            try {
                $operation();

                return;
            } catch (PDOException $exception) {
                $message = strtolower($exception->getMessage());
                if ((!str_contains($message, 'locked') && !str_contains($message, 'busy')) || hrtime(true) >= $deadline) {
                    throw $exception;
                }
                usleep(random_int(2_000, 20_000));
            }
        }
    }

    private function validateScope(string $scope): void
    {
        if ($scope === '' || strlen($scope) > 128 || preg_match('/[\x00-\x1F\x7F]/', $scope) === 1) {
            throw new \InvalidArgumentException('Crawl deduplication scopes must contain 1 to 128 printable bytes.');
        }
    }

    private function validateHash(string $hash, string $name): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new \InvalidArgumentException(sprintf('%s must be a lowercase SHA-256 hex digest.', $name));
        }
    }
}
