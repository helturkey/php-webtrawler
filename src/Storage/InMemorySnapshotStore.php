<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Storage;

use HElturkey\WebTrawler\Adaptive\ElementSnapshot;
use HElturkey\WebTrawler\Contracts\SnapshotStore;

final class InMemorySnapshotStore implements SnapshotStore
{
    /** @var array<string, ElementSnapshot> */
    private array $snapshots = [];

    public function put(string $key, ElementSnapshot $snapshot): void
    {
        $this->assertKey($key);
        $this->snapshots[$key] = $snapshot;
    }

    public function get(string $key): ?ElementSnapshot
    {
        $this->assertKey($key);

        return $this->snapshots[$key] ?? null;
    }

    public function all(): array
    {
        return $this->snapshots;
    }

    public function remove(string $key): void
    {
        $this->assertKey($key);
        unset($this->snapshots[$key]);
    }

    private function assertKey(string $key): void
    {
        if (trim($key) === '') {
            throw new \InvalidArgumentException('Snapshot key cannot be empty.');
        }
    }
}
