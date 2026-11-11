<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Contracts\HttpTransport;
use HElturkey\WebTrawler\Exception\NetworkException;
use HElturkey\WebTrawler\Exception\ProxyPoolExhaustedException;
use HElturkey\WebTrawler\Http\AutoThrottle;
use HElturkey\WebTrawler\Http\FetchResponse;
use HElturkey\WebTrawler\Http\ProxyPool;
use HElturkey\WebTrawler\Http\ProxyTransport;
use HElturkey\WebTrawler\Http\RetryingTransport;
use HElturkey\WebTrawler\Http\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class ProxyAndThrottleTest extends TestCase
{
    public function test_round_robin_skips_unhealthy_proxies_and_recovers_after_the_cooldown(): void
    {
        $now = 1000;
        $pool = new ProxyPool(['http://a:1', 'http://b:2', 'http://c:3'], maxConsecutiveFailures: 2, cooldownSeconds: 30, clock: static function () use (&$now): int {
            return $now;
        });

        self::assertSame(['http://a:1', 'http://b:2', 'http://c:3', 'http://a:1'], [$pool->acquire(), $pool->acquire(), $pool->acquire(), $pool->acquire()]);

        $pool->reportFailure('http://b:2');
        $pool->reportFailure('http://b:2');
        $picked = [$pool->acquire(), $pool->acquire(), $pool->acquire(), $pool->acquire()];
        self::assertNotContains('http://b:2', $picked);

        $now += 31;
        self::assertContains('http://b:2', [$pool->acquire(), $pool->acquire(), $pool->acquire()], 'Half-open: the proxy is probed again after the cooldown.');
        $pool->reportSuccess('http://b:2');
        self::assertTrue($pool->stats()[1]['healthy']);
    }

    public function test_the_pool_reports_exhaustion_and_never_leaks_credentials(): void
    {
        $pool = new ProxyPool(['http://user:s3cret@a:1'], maxConsecutiveFailures: 1, cooldownSeconds: 60, clock: static fn (): int => 0);
        $pool->reportFailure('http://user:s3cret@a:1');

        self::assertNull($pool->acquire());
        self::assertStringNotContainsString('s3cret', (string) json_encode($pool->stats()));
        self::assertSame('http://***@a:1', ProxyPool::mask('http://user:s3cret@a:1'));

        try {
            new ProxyPool(['ftp://user:s3cret@a:1']);
            self::fail('Invalid scheme.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringNotContainsString('s3cret', $exception->getMessage());
        }
    }

    public function test_only_one_request_can_probe_a_proxy_during_half_open_state(): void
    {
        $now = 100;
        $proxy = 'http://proxy.test:8080';
        $pool = new ProxyPool([$proxy], maxConsecutiveFailures: 1, cooldownSeconds: 10, clock: static function () use (&$now): int {
            return $now;
        });
        $pool->reportFailure($proxy);
        $now += 11;

        self::assertSame($proxy, $pool->acquire());
        self::assertNull($pool->acquire(), 'Concurrent callers must not create multiple half-open probes.');
        self::assertTrue($pool->stats()[0]['half_open_probe_in_flight']);

        $pool->reportSuccess($proxy);
        self::assertSame($proxy, $pool->acquire());
    }

    public function test_proxy_stats_redact_credentials_in_paths_queries_and_fragments(): void
    {
        $masked = ProxyPool::mask('https://user:password@proxy.test:8443/path?token=secret#fragment');
        self::assertSame('https://***@proxy.test:8443', $masked);
        self::assertStringNotContainsString('secret', $masked);
        self::assertStringNotContainsString('password', $masked);
    }

    public function test_least_failures_prefers_the_healthiest_proxy(): void
    {
        $pool = new ProxyPool(['http://a:1', 'http://b:2'], ProxyPool::LEAST_FAILURES, maxConsecutiveFailures: 5);
        $pool->reportFailure('http://a:1');

        self::assertSame('http://b:2', $pool->acquire());
    }

    /** @param list<int|\Throwable> $script */
    private function recording(array $script): object
    {
        return new class ($script) implements HttpTransport {
            /** @var list<mixed> */
            public array $proxies = [];

            /** @param list<int|\Throwable> $script */
            public function __construct(private array $script)
            {
            }

            public function send(string $method, string $url, array $options = []): FetchResponse
            {
                $this->proxies[] = $options['proxy'] ?? null;
                $step = array_shift($this->script) ?? 200;
                if ($step instanceof \Throwable) {
                    throw $step;
                }

                return new FetchResponse($step, [], 'b', $url, $url, 1.0);
            }
        };
    }

    public function test_the_proxy_transport_rotates_learns_from_failures_and_respects_explicit_proxies(): void
    {
        $inner = $this->recording([new NetworkException('down'), 200, 407, 200]);
        $pool = new ProxyPool(['http://a:1', 'http://b:2'], maxConsecutiveFailures: 1, cooldownSeconds: 600, clock: static fn (): int => 0);
        $transport = new ProxyTransport($inner, $pool);

        try {
            $transport->send('GET', 'https://example.test/');
            self::fail('The first proxy fails.');
        } catch (NetworkException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(200, $transport->send('GET', 'https://example.test/')->status);
        self::assertSame(['http://a:1', 'http://b:2'], $inner->proxies);

        // b now answers 407 (proxy auth) -> counted as a failure; a is cooling down -> exhausted.
        $transport->send('GET', 'https://example.test/');
        $this->expectException(ProxyPoolExhaustedException::class);
        $transport->send('GET', 'https://example.test/');
    }

    public function test_an_explicit_proxy_wins_and_direct_fallback_is_opt_in(): void
    {
        $inner = $this->recording([200, 200]);
        $pool = new ProxyPool(['http://a:1'], maxConsecutiveFailures: 1, clock: static fn (): int => 0);
        $pool->reportFailure('http://a:1');

        (new ProxyTransport($inner, $pool))->send('GET', 'https://example.test/', ['proxy' => 'http://mine:9']);
        (new ProxyTransport($inner, $pool, allowDirectFallback: true))->send('GET', 'https://example.test/');

        self::assertSame(['http://mine:9', null], $inner->proxies);
    }

    public function test_an_enforced_proxy_pool_rejects_explicit_proxy_overrides(): void
    {
        $inner = $this->recording([200]);
        $pool = new ProxyPool(['http://pool-proxy:8080']);
        $transport = new ProxyTransport($inner, $pool, allowExplicitProxyOverride: false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot bypass the configured proxy pool');
        $transport->send('GET', 'https://example.test/', ['proxy' => 'http://other-proxy:8080']);
    }

    public function test_retries_wrapped_around_the_pool_use_the_next_proxy(): void
    {
        $inner = $this->recording([new NetworkException('down'), 200]);
        $pool = new ProxyPool(['http://a:1', 'http://b:2'], maxConsecutiveFailures: 1, clock: static fn (): int => 0);
        $transport = new RetryingTransport(new ProxyTransport($inner, $pool), new RetryPolicy(maxAttempts: 2, baseDelayMs: 0), static function (int $ms): void {
        });

        self::assertSame(200, $transport->send('GET', 'https://example.test/')->status);
        self::assertSame(['http://a:1', 'http://b:2'], $inner->proxies);
    }

    public function test_retry_policy_math(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 100, maxDelayMs: 1000, jitter: RetryPolicy::JITTER_NONE);
        self::assertSame([100, 200, 400, 800, 1000], array_map($policy->delayMs(...), [1, 2, 3, 4, 5]));
        self::assertSame(1000, $policy->delayMs(1, '1'), 'Retry-After may raise the delay');
        self::assertSame(1000, $policy->delayMs(1, '3600'), '... but never beyond the cap');
        self::assertSame(100, (new RetryPolicy(baseDelayMs: 100, jitter: RetryPolicy::JITTER_NONE, respectRetryAfter: false))->delayMs(1, '30'));
        self::assertSame(300, (new RetryPolicy(baseDelayMs: 300, multiplier: 1.0, jitter: RetryPolicy::JITTER_NONE))->delayMs(4));

        foreach ([RetryPolicy::JITTER_EQUAL => [200, 400], RetryPolicy::JITTER_FULL => [0, 400]] as $mode => [$min, $max]) {
            $jittered = new RetryPolicy(baseDelayMs: 200, jitter: $mode);
            for ($i = 0; $i < 50; $i++) {
                self::assertGreaterThanOrEqual($min === 200 ? 100 : 0, $jittered->delayMs(2));
                self::assertLessThanOrEqual($max, $jittered->delayMs(2));
            }
        }
        self::assertNull(RetryPolicy::parseRetryAfter('garbage'));
        self::assertSame(0, RetryPolicy::parseRetryAfter('Wed, 21 Oct 2015 07:28:00 GMT'));
        self::assertFalse(RetryPolicy::none()->retriesEnabled());
    }

    public function test_retry_and_auto_throttle_reject_non_finite_numeric_settings(): void
    {
        foreach ([INF, NAN] as $multiplier) {
            try {
                new RetryPolicy(multiplier: $multiplier);
                self::fail('Non-finite retry multipliers must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('multiplier', $exception->getMessage());
            }
        }

        foreach ([INF, NAN] as $concurrency) {
            try {
                new AutoThrottle(targetConcurrency: $concurrency);
                self::fail('Non-finite concurrency must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('target concurrency', $exception->getMessage());
            }
        }

        $throttle = new AutoThrottle();
        foreach ([INF, NAN, -1.0] as $latency) {
            try {
                $throttle->record('example.test', $latency, 200);
                self::fail('Invalid latency measurements must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('latency', $exception->getMessage());
            }
        }
    }

    public function test_auto_throttle_tracks_latency_and_never_speeds_up_on_errors(): void
    {
        $throttle = new AutoThrottle(targetConcurrency: 2.0, startDelayMs: 1000, minDelayMs: 100, maxDelayMs: 5000);
        self::assertSame(1000, $throttle->delayMs('Example.test'));

        $throttle->record('example.test', 200.0, 200);    // (1000 + 100) / 2 = 550
        self::assertSame(550, $throttle->delayMs('example.test'));
        $throttle->record('example.test', 8000.0, 200);   // (550 + 4000) / 2 = 2275
        self::assertSame(2275, $throttle->delayMs('example.test'));
        $throttle->record('example.test', 10.0, 503);     // would lower the delay: ignored for errors
        self::assertSame(2275, $throttle->delayMs('example.test'));
        $throttle->record('example.test', 100_000.0, 200);
        self::assertLessThanOrEqual(5000, $throttle->delayMs('example.test'));
        $throttle->record('other.test', 1.0, 200);
        self::assertGreaterThanOrEqual(100, $throttle->delayMs('other.test'));
        self::assertSame(1000, $throttle->delayMs('fresh.test'), 'Hosts are tracked independently.');
    }
}
