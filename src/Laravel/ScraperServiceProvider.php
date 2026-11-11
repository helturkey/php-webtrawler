<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Laravel;

use HElturkey\WebTrawler\Contracts\HttpTransport;
use HElturkey\WebTrawler\Contracts\SnapshotStore;
use HElturkey\WebTrawler\Http\FetchOptions;
use HElturkey\WebTrawler\Http\GuzzleTransport;
use HElturkey\WebTrawler\Http\ImpityTransport;
use HElturkey\WebTrawler\Http\ProxyPool;
use HElturkey\WebTrawler\Http\ProxyTransport;
use HElturkey\WebTrawler\Http\RetryPolicy;
use HElturkey\WebTrawler\Http\RnetTransport;
use HElturkey\WebTrawler\Laravel\Console\FetchCommand;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Storage\JsonSnapshotStore;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Registers a configured Scraper singleton (config/scraper.php), the Scraper facade and `php artisan scraper:fetch`.
 * Auto-discovered through composer.json "extra.laravel".
 */
final class ScraperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/scraper.php', 'scraper');

        $this->app->singleton(Scraper::class, function (Application $app): Scraper {
            $configRepository = $app->make('config');
            if (!$configRepository instanceof ConfigRepository) {
                throw new \LogicException('The Laravel config repository is not available.');
            }

            $rawConfig = $configRepository->get('scraper', []);
            if (!is_array($rawConfig)) {
                throw new \InvalidArgumentException('The scraper configuration must be an array.');
            }
            /** @var array<string, mixed> $config */
            $config = $rawConfig;

            $retries = $config['retries'] ?? [];
            if (!is_array($retries)) {
                throw new \InvalidArgumentException('The scraper retries setting must be an array.');
            }
            $attempts = $retries['max_attempts'] ?? 1;
            $baseDelay = $retries['base_delay_ms'] ?? 500;
            $maxDelay = $retries['max_delay_ms'] ?? 30_000;
            if (!is_int($attempts) || $attempts < 1 || !is_int($baseDelay) || $baseDelay < 0 || !is_int($maxDelay) || $maxDelay < $baseDelay) {
                throw new \InvalidArgumentException('Scraper retry attempts/delays must be valid positive/non-negative integers.');
            }
            $retryPolicy = $attempts > 1 ? new RetryPolicy(
                maxAttempts: $attempts,
                baseDelayMs: $baseDelay,
                maxDelayMs: $maxDelay,
            ) : null;

            $timeout = $config['timeout'] ?? 30;
            $connectTimeout = $config['connect_timeout'] ?? 10;
            if ((!is_int($timeout) && !is_float($timeout) && !is_string($timeout)) || !is_numeric($timeout) || !is_finite((float) $timeout) || (float) $timeout < 0) {
                throw new \InvalidArgumentException('The scraper timeout setting must be a finite non-negative number.');
            }
            if ((!is_int($connectTimeout) && !is_float($connectTimeout) && !is_string($connectTimeout)) || !is_numeric($connectTimeout) || !is_finite((float) $connectTimeout) || (float) $connectTimeout < 0) {
                throw new \InvalidArgumentException('The scraper connect_timeout setting must be a finite non-negative number.');
            }
            $maxBodyBytes = $config['max_body_bytes'] ?? null;
            if (is_string($maxBodyBytes) && filter_var($maxBodyBytes, FILTER_VALIDATE_INT) !== false) {
                $maxBodyBytes = (int) $maxBodyBytes;
            }
            if ($maxBodyBytes !== null && (!is_int($maxBodyBytes) || $maxBodyBytes < 1)) {
                throw new \InvalidArgumentException('The scraper max_body_bytes setting must be a positive integer or null.');
            }
            $blockPrivateNetworks = $config['block_private_networks'] ?? true;
            $enforceDefaultSecurity = $config['enforce_default_security'] ?? true;
            if (!is_bool($blockPrivateNetworks) || !is_bool($enforceDefaultSecurity)) {
                throw new \InvalidArgumentException('Scraper security settings block_private_networks and enforce_default_security must be booleans.');
            }
            $allowedHosts = $config['allowed_hosts'] ?? [];
            if (!is_array($allowedHosts) || !array_is_list($allowedHosts) || array_filter($allowedHosts, static fn (mixed $host): bool => is_string($host) && trim($host, ". \t") !== '') !== $allowedHosts) {
                throw new \InvalidArgumentException('The scraper allowed_hosts setting must be a list of non-empty host names.');
            }

            return new Scraper(
                $this->transport($config),
                $this->snapshotStore($config),
                null,
                new FetchOptions(
                    timeout: (float) $timeout,
                    connectTimeout: (float) $connectTimeout,
                    maxBodyBytes: $maxBodyBytes,
                    blockPrivateNetworks: $blockPrivateNetworks,
                    allowedHosts: $allowedHosts,
                ),
                $enforceDefaultSecurity,
                $retryPolicy,
            );
        });
        $this->app->alias(Scraper::class, 'scraper');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../../config/scraper.php' => $this->app->configPath('scraper.php')], 'scraper-config');
            $this->commands([FetchCommand::class]);
        }
    }

    /** @param array<string, mixed> $config */
    private function transport(array $config): HttpTransport
    {
        $transportName = $config['transport'] ?? 'guzzle';
        if (!is_string($transportName)) {
            throw new \InvalidArgumentException('The scraper transport setting must be a string.');
        }
        $transport = match ($transportName) {
            'guzzle' => new GuzzleTransport(),
            'rnet' => new RnetTransport(is_string($config['emulation'] ?? null) ? $config['emulation'] : null),
            'impity' => new ImpityTransport(),
            default => throw new \InvalidArgumentException(sprintf('Unsupported scraper transport "%s". Choose guzzle, rnet or impity.', $transportName)),
        };

        $proxyConfig = $config['proxies'] ?? [];
        if (!is_array($proxyConfig) || !array_is_list($proxyConfig) || array_filter($proxyConfig, static fn (mixed $proxy): bool => is_string($proxy) && trim($proxy) !== '') !== $proxyConfig) {
            throw new \InvalidArgumentException('The scraper proxies setting must be a list of non-empty proxy URLs.');
        }
        $proxies = $proxyConfig;
        if ($proxies !== []) {
            // The configured pool is the application's network route, not a per-request suggestion.
            // Do not let request-level proxy options silently bypass it.
            $transport = new ProxyTransport($transport, new ProxyPool($proxies), allowExplicitProxyOverride: false);
        }

        return $transport;
    }

    /** @param array<string, mixed> $config */
    private function snapshotStore(array $config): SnapshotStore
    {
        $snapshots = (array) ($config['snapshots'] ?? []);
        if (($snapshots['driver'] ?? 'file') === 'memory' || !is_string($snapshots['path'] ?? null)) {
            return new InMemorySnapshotStore();
        }

        return new JsonSnapshotStore($snapshots['path']);
    }
}
