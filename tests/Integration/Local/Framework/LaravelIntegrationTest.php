<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Integration\Local\Framework;

use HElturkey\WebTrawler\Exception\HostNotAllowedException;
use HElturkey\WebTrawler\Exception\UnsafeUrlException;
use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Tests\Support\RecordingTransport;
use HElturkey\WebTrawler\Laravel\ScraperServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Group;
use HElturkey\WebTrawler\Tests\Support\LiveTargets;

final class LaravelIntegrationTest extends TestCase
{
    /** @param \Illuminate\Contracts\Foundation\Application $app */
    protected function getPackageProviders($app): array
    {
        return [ScraperServiceProvider::class];
    }

    /** @param \Illuminate\Contracts\Foundation\Application $app */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('scraper', [
            'transport' => 'guzzle',
            'timeout' => 5,
            'connect_timeout' => 2,
            'max_body_bytes' => 4096,
            'block_private_networks' => true,
            'allowed_hosts' => [],
            'enforce_default_security' => true,
            'retries' => [
                'max_attempts' => 1,
                'base_delay_ms' => 50,
                'max_delay_ms' => 100,
            ],
            'proxies' => [],
            'snapshots' => ['driver' => 'memory'],
        ]);
    }

    public function test_service_provider_registers_a_shared_scraper_singleton_and_memory_store(): void
    {
        $scraper = $this->app->make(Scraper::class);

        self::assertSame($scraper, $this->app->make(Scraper::class));
        self::assertSame($scraper, $this->app->make('scraper'));
        self::assertInstanceOf(InMemorySnapshotStore::class, $scraper->snapshotStore());
    }

    public function test_the_sqlite_snapshot_driver_is_selectable_from_config(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
        $path = sys_get_temp_dir() . '/scraper-laravel-' . bin2hex(random_bytes(4)) . '.sqlite';
        config(['scraper.snapshots' => ['driver' => 'sqlite', 'sqlite_path' => $path]]);
        $this->app->forgetInstance(Scraper::class);

        try {
            self::assertInstanceOf(\HElturkey\WebTrawler\Storage\SQLiteSnapshotStore::class, $this->app->make(Scraper::class)->snapshotStore());
        } finally {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                @unlink($file);
            }
        }
    }

    public function test_an_unknown_snapshot_driver_fails_with_the_valid_choices(): void
    {
        config(['scraper.snapshots' => ['driver' => 'redis']]);
        $this->app->forgetInstance(Scraper::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('memory, file or sqlite');
        $this->app->make(Scraper::class);
    }

    public function test_laravel_security_defaults_cannot_be_disabled_per_request(): void
    {
        $this->expectException(UnsafeUrlException::class);

        $this->app->make(Scraper::class)->get(
            'http://127.0.0.1/',
            new FetchOptions(blockPrivateNetworks: false),
        );
    }

    public function test_configured_host_allow_list_is_enforced(): void
    {
        $this->app['config']->set('scraper.allowed_hosts', ['allowed.example']);
        $this->app->forgetInstance(Scraper::class);

        $this->expectException(HostNotAllowedException::class);

        $this->app->make(Scraper::class)->get('https://outside.example/');
    }

    public function test_artisan_command_uses_the_container_scraper(): void
    {
        $url = 'https://allowed.example/fixture';
        $transport = new RecordingTransport([
            $url => [200, '<!doctype html><html><body><p>Laravel integration works</p></body></html>'],
        ]);
        $this->app->instance(Scraper::class, Scraper::create($transport));

        $this->artisan('scraper:fetch', ['url' => $url])
            ->expectsOutput('Laravel integration works')
            ->assertExitCode(0);

        self::assertSame([$url], $transport->urls());
    }

    #[Group('live')]
    public function test_artisan_command_fetches_quotes_from_the_real_example_site(): void
    {
        if (getenv('SCRAPER_RUN_LIVE_TESTS') !== '1') {
            self::markTestSkipped('Set SCRAPER_RUN_LIVE_TESTS=1 to run the Laravel live integration test.');
        }

        config([
            'scraper.timeout' => 45,
            'scraper.connect_timeout' => 15,
            'scraper.max_body_bytes' => 8 * 1024 * 1024,
            'scraper.block_private_networks' => true,
            'scraper.allowed_hosts' => [LiveTargets::QUOTES_HOST],
            'scraper.enforce_default_security' => true,
        ]);
        $this->app->forgetInstance(Scraper::class);

        $this->artisan('scraper:fetch', [
            'url' => LiveTargets::QUOTES_URL,
            '--select' => '.quote .text',
        ])->assertExitCode(0);
    }

}
