<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Contracts;

use HElturkey\WebTrawler\Adaptive\ElementSnapshot;

interface SnapshotStore
{
    public function put(string $key, ElementSnapshot $snapshot): void;

    public function get(string $key): ?ElementSnapshot;

    /** @return array<string, ElementSnapshot> */
    public function all(): array;

    public function remove(string $key): void;
}
