<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Storage;

use HElturkey\WebTrawler\Adaptive\ElementSnapshot;
use HElturkey\WebTrawler\Contracts\SnapshotStore;
use RuntimeException;

/** File-backed store suitable for a single host. Use a shared database store for multi-host workers. */
final class JsonSnapshotStore implements SnapshotStore
{
    public function __construct(private readonly string $filePath)
    {
        $directory = dirname($filePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create snapshot directory: %s', $directory));
        }
    }

    public function put(string $key, ElementSnapshot $snapshot): void
    {
        $this->mutate(function (array &$data) use ($key, $snapshot): void {
            $data[$this->validateKey($key)] = $snapshot->toArray();
        });
    }

    public function get(string $key): ?ElementSnapshot
    {
        $data = $this->read();
        $stored = $data[$this->validateKey($key)] ?? null;

        return is_array($stored) ? ElementSnapshot::fromArray($stored) : null;
    }

    public function all(): array
    {
        $result = [];
        foreach ($this->read() as $key => $data) {
            if (is_array($data)) {
                $result[$key] = ElementSnapshot::fromArray($data);
            }
        }

        return $result;
    }

    public function remove(string $key): void
    {
        $this->mutate(function (array &$data) use ($key): void {
            unset($data[$this->validateKey($key)]);
        });
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }

        $contents = file_get_contents($this->filePath);
        if ($contents === false || trim($contents) === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Snapshot store contains invalid JSON.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Snapshot store root must be a JSON object.');
        }

        return $decoded;
    }

    /** @param callable(array<string, mixed>&): void $callback */
    private function mutate(callable $callback): void
    {
        $lockPath = $this->filePath . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException(sprintf('Unable to open snapshot lock: %s', $lockPath));
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire snapshot store lock.');
            }

            $data = $this->read();
            $callback($data);
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $temporaryPath = $this->filePath . '.' . bin2hex(random_bytes(6)) . '.tmp';

            if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write temporary snapshot store.');
            }

            if (!rename($temporaryPath, $this->filePath)) {
                @unlink($temporaryPath);
                throw new RuntimeException('Unable to replace snapshot store atomically.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function validateKey(string $key): string
    {
        if ($key === '' || strlen($key) > 190) {
            throw new \InvalidArgumentException('Snapshot keys must contain 1 to 190 bytes.');
        }

        return $key;
    }
}
