<?php

declare(strict_types=1);

// Child process used by SnapshotStoreMultiProcessTest: php snapshot-writer.php <sqlite|json> <path> <worker> <count>
use HElturkey\WebTrawler\Adaptive\ElementSnapshot;
use HElturkey\WebTrawler\Storage\JsonSnapshotStore;
use HElturkey\WebTrawler\Storage\SQLiteSnapshotStore;

require __DIR__ . '/../../vendor/autoload.php';

[, $kind, $path, $worker, $count] = $argv;
$store = $kind === 'json' ? new JsonSnapshotStore($path) : new SQLiteSnapshotStore($path);

for ($i = 0; $i < (int) $count; $i++) {
    $make = static fn (string $text): ElementSnapshot => new ElementSnapshot(
        tag: 'p',
        text: $text,
        attributes: [],
        classes: [],
        parent: null,
        ancestors: [],
        siblings: [],
        sameTagPosition: 1,
        sourceUrl: 'https://example.test',
        createdAt: 1,
    );
    $store->put("worker-$worker-key-$i", $make("worker $worker item $i"));
    $store->put('shared', $make("last writer $worker"));
}
exit(0);
