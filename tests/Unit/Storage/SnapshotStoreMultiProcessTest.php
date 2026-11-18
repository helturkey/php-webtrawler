<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Storage;

use HElturkey\WebTrawler\Storage\JsonSnapshotStore;
use HElturkey\WebTrawler\Storage\SQLiteSnapshotStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Several real PHP processes write to one store at the same time; nothing may be lost or corrupted. */
final class SnapshotStoreMultiProcessTest extends TestCase
{
    private const WORKERS = 6;
    private const KEYS_PER_WORKER = 25;

    private string $directory;

    protected function setUp(): void
    {
        if (!function_exists('proc_open') || !is_file(dirname(__DIR__, 3) . '/vendor/autoload.php')) {
            self::markTestSkipped('Child processes or the Composer autoloader are not available.');
        }
        $this->directory = sys_get_temp_dir() . '/php-webtrawler-mp-' . bin2hex(random_bytes(5));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    /** @return iterable<string, array{string}> */
    public static function stores(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'json' => ['json'];
    }

    #[DataProvider('stores')]
    public function test_concurrent_writers_lose_nothing(string $kind): void
    {
        if ($kind === 'sqlite' && !extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
        $path = $this->directory . '/store.' . $kind;
        $processes = [];
        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/snapshot-writer.php', $kind, $path, (string) $worker, (string) self::KEYS_PER_WORKER],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        $failures = [];
        foreach ($processes as $index => [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                $failures[] = sprintf('worker %d: %s', $index, trim($output));
            }
        }
        self::assertSame([], $failures, 'Every writer process must finish without a lock or write error.');

        $store = $kind === 'sqlite' ? new SQLiteSnapshotStore($path) : new JsonSnapshotStore($path);
        $all = $store->all();

        self::assertCount(self::WORKERS * self::KEYS_PER_WORKER + 1, $all, 'Every private key plus the contended one.');
        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            for ($i = 0; $i < self::KEYS_PER_WORKER; $i++) {
                self::assertSame("worker $worker item $i", $store->get("worker-$worker-key-$i")?->text);
            }
        }
        self::assertStringStartsWith('last writer ', $store->get('shared')?->text ?? '', 'A contended key holds one complete value, never a mix.');
    }
}
