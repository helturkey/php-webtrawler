<?php

declare(strict_types=1);

/**
 * Frontier benchmark: how fast can a crawl decide "have I seen this URL?"
 *
 *   php benchmarks/frontier.php [urls=200000] [batch=100]
 *
 * It compares three strategies on the same synthetic URL stream (every URL appears twice, so half the answers are
 * "duplicate"): the in-memory map the spider uses by default, the SQLite store one URL per transaction, and the
 * SQLite store in batches (what SpiderRunner does when links are found on a page). Numbers depend on your disk and
 * PHP build; run it on the machine that will run the crawl and keep the output with your results.
 */

use HElturkey\WebTrawler\Storage\SQLiteCrawlDeduplicator;

require __DIR__ . '/../vendor/autoload.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required.\n");
    exit(1);
}

$total = max(1_000, (int) ($argv[1] ?? 200_000));
$batchSize = max(1, (int) ($argv[2] ?? 100));
$unique = intdiv($total, 2);

$fingerprints = [];
for ($i = 0; $i < $total; $i++) {
    $fingerprints[] = hash('sha256', 'https://bench.test/page/' . ($i % $unique));
}

/** @return array{seconds: float, duplicates: int, peakMb: float} */
function measure(callable $run): array
{
    gc_collect_cycles();
    $started = hrtime(true);
    $duplicates = $run();

    return [
        'seconds' => (hrtime(true) - $started) / 1e9,
        'duplicates' => $duplicates,
        'peakMb' => memory_get_peak_usage(true) / 1_048_576,
    ];
}

$results = [];

$results['memory map'] = measure(static function () use ($fingerprints): int {
    $seen = [];
    $duplicates = 0;
    foreach ($fingerprints as $fingerprint) {
        if (isset($seen[$fingerprint])) {
            $duplicates++;
            continue;
        }
        $seen[$fingerprint] = true;
    }

    return $duplicates;
});

$database = sys_get_temp_dir() . '/webtrawler-frontier-bench-' . bin2hex(random_bytes(4)) . '.sqlite';
try {
    $results['sqlite, one write per URL'] = measure(static function () use ($fingerprints, $database, $unique): int {
        $store = new SQLiteCrawlDeduplicator($database . '.single', expectedUrls: $unique);
        $duplicates = 0;
        foreach ($fingerprints as $fingerprint) {
            $duplicates += $store->markUrlSeen('bench', $fingerprint) ? 1 : 0;
        }
        $store->close();

        return $duplicates;
    });

    $results[sprintf('sqlite, batches of %d', $batchSize)] = measure(static function () use ($fingerprints, $database, $unique, $batchSize): int {
        $store = new SQLiteCrawlDeduplicator($database . '.batch', expectedUrls: $unique);
        $duplicates = 0;
        foreach (array_chunk($fingerprints, $batchSize) as $chunk) {
            $duplicates += count(array_filter($store->markUrlsSeenBatch('bench', $chunk)));
        }
        $store->close();

        return $duplicates;
    });
} finally {
    foreach (glob($database . '*') ?: [] as $file) {
        @unlink($file);
    }
}

printf("PHP %s, %d fingerprints (%d unique)\n\n", PHP_VERSION, $total, $unique);
printf("%-28s %10s %14s %10s %10s\n", 'strategy', 'seconds', 'URLs/second', 'dups', 'peak MB');
foreach ($results as $name => $result) {
    printf("%-28s %10.3f %14s %10d %10.1f\n", $name, $result['seconds'], number_format($total / max($result['seconds'], 1e-9), 0), $result['duplicates'], $result['peakMb']);
}

$expected = $total - $unique;
foreach ($results as $name => $result) {
    if ($result['duplicates'] !== $expected) {
        fwrite(STDERR, sprintf("\nWARNING: %s reported %d duplicates, expected %d.\n", $name, $result['duplicates'], $expected));
        exit(1);
    }
}
