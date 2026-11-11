<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Exception\HostNotAllowedException;
use HElturkey\WebTrawler\Exception\UnsafeUrlException;
use HElturkey\WebTrawler\Http\FetchOptions;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Tests\Support\RecordingTransport;
use HElturkey\WebTrawler\Laravel\ScraperServiceProvider;
use Orchestra\Testbench\TestCase;

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
}
