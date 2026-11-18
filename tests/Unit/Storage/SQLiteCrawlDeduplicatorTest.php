<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Storage;

use HElturkey\WebTrawler\Contracts\BloomFilter as BloomFilterContract;
use HElturkey\WebTrawler\Storage\SQLiteCrawlDeduplicator;
use PHPUnit\Framework\TestCase;

final class SQLiteCrawlDeduplicatorTest extends TestCase
{
    public function test_a_bloom_false_positive_never_drops_a_new_url(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not installed.');
        }

        $store = new SQLiteCrawlDeduplicator(':memory:', bloomFilter: new AlwaysPositiveBloomFilter());
        $first = hash('sha256', 'first');
        $new = hash('sha256', 'new');

        self::assertFalse($store->markUrlSeen('scope', $first));
        self::assertTrue($store->markUrlSeen('scope', $first));
        self::assertFalse($store->markUrlSeen('scope', $new), 'A Bloom positive must be checked against SQLite before deduplicating.');
        self::assertTrue($store->markUrlSeen('scope', $new));
    }

    public function test_url_fingerprints_survive_reopening_the_sqlite_store(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not installed.');
        }

        $path = sys_get_temp_dir() . '/webtrawler-dedup-' . bin2hex(random_bytes(8)) . '.sqlite';
        try {
            $fingerprint = hash('sha256', 'request');
            $first = new SQLiteCrawlDeduplicator($path, expectedUrls: 10);
            self::assertFalse($first->markUrlSeen('scope', $fingerprint));
            $first->close();

            $second = new SQLiteCrawlDeduplicator($path, expectedUrls: 10);
            self::assertTrue($second->markUrlSeen('scope', $fingerprint));
            $second->clearScope('scope');
            self::assertFalse($second->markUrlSeen('scope', $fingerprint));
            $second->close();
        } finally {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_purge_removes_only_records_older_than_the_given_age(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not installed.');
        }

        $path = sys_get_temp_dir() . '/webtrawler-purge-' . bin2hex(random_bytes(8)) . '.sqlite';
        try {
            $store = new SQLiteCrawlDeduplicator($path, expectedUrls: 10);
            $old = hash('sha256', 'old');
            $recent = hash('sha256', 'recent');
            self::assertFalse($store->markUrlSeen('crashed-crawl', $old));
            self::assertFalse($store->recordContent('crashed-crawl', 'https://example.test/old', $old, 3));
            self::assertFalse($store->markUrlSeen('live-crawl', $recent));

            // Simulate a crawl that was killed two hours ago and never cleared its scope.
            $raw = new \PDO('sqlite:' . $path);
            $raw->exec('PRAGMA busy_timeout = 5000');
            $raw->exec("UPDATE crawl_url_fingerprints SET created_at = created_at - 7200 WHERE scope = 'crashed-crawl'");
            $raw->exec("UPDATE crawl_content_fingerprints SET created_at = created_at - 7200 WHERE scope = 'crashed-crawl'");
            $raw = null;

            self::assertSame(2, $store->purgeOlderThan(3600));
            self::assertFalse($store->markUrlSeen('crashed-crawl', $old), 'A purged fingerprint is new again.');
            self::assertTrue($store->markUrlSeen('live-crawl', $recent), 'Recent records are kept.');
            self::assertSame(0, $store->purgeOlderThan(3600));
            $store->close();
        } finally {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_purge_rejects_a_negative_age(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not installed.');
        }

        $this->expectException(\InvalidArgumentException::class);
        (new SQLiteCrawlDeduplicator(':memory:'))->purgeOlderThan(-1);
    }

    public function test_it_detects_exact_content_duplicates_across_urls(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not installed.');
        }

        $store = new SQLiteCrawlDeduplicator(':memory:');
        $hash = hash('sha256', 'same body');

        self::assertFalse($store->recordContent('scope', 'https://example.test/a', $hash, 9));
        self::assertTrue($store->recordContent('scope', 'https://example.test/b', $hash, 9));
        self::assertFalse($store->recordContent('scope', 'https://example.test/a', $hash, 9));
    }
}

final class AlwaysPositiveBloomFilter implements BloomFilterContract
{
    public function add(string $value): void
    {
    }

    public function mightContain(string $value): bool
    {
        return true;
    }

    public function clear(): void
    {
    }
}
