<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Transport;

use HElturkey\WebTrawler\Exception\NetworkException;
use HElturkey\WebTrawler\Tests\Support\RecordingTransport;
use HElturkey\WebTrawler\Transport\CachingTransport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CachingTransportTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/webtrawler-cache-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (scandir($this->directory) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    @unlink($this->directory . '/' . $name);
                }
            }
            rmdir($this->directory);
        }
    }

    public function test_the_second_request_is_served_from_disk(): void
    {
        $inner = new RecordingTransport(['https://a.test/' => [200, "binary\x00\xFFbody"]]);
        $cache = new CachingTransport($inner, $this->directory);

        $first = $cache->send('GET', 'https://a.test/');
        $second = $cache->send('GET', 'https://a.test/');

        self::assertCount(1, $inner->calls);
        self::assertSame("binary\x00\xFFbody", $second->body);
        self::assertSame($first->status, $second->status);
        self::assertSame('hit', $second->meta['cache'] ?? null);
        self::assertArrayNotHasKey('cache', $first->meta);
    }

    public function test_headers_and_query_take_part_in_the_key(): void
    {
        $inner = new RecordingTransport();
        $cache = new CachingTransport($inner, $this->directory);

        $cache->send('GET', 'https://a.test/', ['headers' => ['Accept-Language' => 'en']]);
        $cache->send('GET', 'https://a.test/', ['headers' => ['Accept-Language' => 'ar']]);
        $cache->send('GET', 'https://a.test/', ['headers' => ['Accept-Language' => 'ar'], 'timeout' => 5]);

        self::assertCount(2, $inner->calls, 'Timeouts do not change the response, so they are not part of the key.');
    }

    public function test_only_plain_get_requests_and_non_failing_statuses_are_cached(): void
    {
        $inner = new RecordingTransport(['https://a.test/err' => [503, 'down'], 'https://a.test/limited' => [429, 'slow']]);
        $cache = new CachingTransport($inner, $this->directory);

        $cache->send('POST', 'https://a.test/', ['form_params' => ['a' => 1]]);
        $cache->send('POST', 'https://a.test/', ['form_params' => ['a' => 1]]);
        $cache->send('GET', 'https://a.test/err');
        $cache->send('GET', 'https://a.test/err');
        $cache->send('GET', 'https://a.test/limited');
        $cache->send('GET', 'https://a.test/limited');

        self::assertCount(6, $inner->calls);
    }

    public function test_offline_mode_replays_hits_and_fails_on_misses(): void
    {
        $inner = new RecordingTransport();
        (new CachingTransport($inner, $this->directory))->send('GET', 'https://a.test/known');

        $offline = new CachingTransport($inner, $this->directory, offline: true);
        self::assertSame(200, $offline->send('GET', 'https://a.test/known')->status);
        self::assertCount(1, $inner->calls);

        $this->expectException(NetworkException::class);
        $offline->send('GET', 'https://a.test/unknown');
    }

    public function test_offline_mode_refuses_requests_it_cannot_replay(): void
    {
        $offline = new CachingTransport(new RecordingTransport(), $this->directory, offline: true);

        $this->expectException(NetworkException::class);
        $offline->send('POST', 'https://a.test/', ['json' => ['a' => 1]]);
    }

    public function test_expired_entries_are_fetched_again(): void
    {
        $inner = new RecordingTransport();
        $cache = new CachingTransport($inner, $this->directory, ttlSeconds: 60);
        $cache->send('GET', 'https://a.test/');

        $file = glob($this->directory . '/*.json')[0];
        $entry = json_decode((string) file_get_contents($file), true);
        $entry['stored_at'] -= 3_600;
        file_put_contents($file, json_encode($entry));

        $cache->send('GET', 'https://a.test/');

        self::assertCount(2, $inner->calls);
    }

    public function test_corrupt_entries_are_ignored_and_clear_removes_everything(): void
    {
        $inner = new RecordingTransport();
        $cache = new CachingTransport($inner, $this->directory);
        $cache->send('GET', 'https://a.test/');
        file_put_contents(glob($this->directory . '/*.json')[0], '{not json');

        $cache->send('GET', 'https://a.test/');
        self::assertCount(2, $inner->calls);

        self::assertSame(1, $cache->clear());
        self::assertSame([], glob($this->directory . '/*.json'));
    }

    public function test_entries_are_private_to_the_owner(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('POSIX permissions only.');
        }
        $cache = new CachingTransport(new RecordingTransport(), $this->directory);
        $cache->send('GET', 'https://a.test/');

        self::assertSame(0600, fileperms(glob($this->directory . '/*.json')[0]) & 0777);
        self::assertSame(0700, fileperms($this->directory) & 0777);
    }

    public function test_invalid_configuration_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CachingTransport(new RecordingTransport(), '');
    }
}
