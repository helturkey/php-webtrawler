<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use HElturkey\WebTrawler\Contracts\HttpTransport;
use HElturkey\WebTrawler\Http\FetchResponse;
use HElturkey\WebTrawler\Http\RetryPolicy;
use HElturkey\WebTrawler\Http\RetryingTransport;
use PHPUnit\Framework\TestCase;

final class RetryingTransportTest extends TestCase
{
    /** @param list<int|\Throwable> $script */
    private function scripted(array $script, array $headers = []): object
    {
        return new class ($script, $headers) implements HttpTransport {
            public int $calls = 0;

            /** @param list<int|\Throwable> $script */
            public function __construct(private array $script, private array $headers)
            {
            }

            public function send(string $method, string $url, array $options = []): FetchResponse
            {
                $step = $this->script[min($this->calls++, count($this->script) - 1)];
                if ($step instanceof \Throwable) {
                    throw $step;
                }

                return new FetchResponse($step, $this->headers, 'b', $url, $url, 1.0);
            }
        };
    }

    public function test_it_retries_transient_statuses_with_exponential_backoff(): void
    {
        $inner = $this->scripted([503, 503, 200]);
        $sleeps = [];
        $transport = new RetryingTransport($inner, new RetryPolicy(maxAttempts: 3, baseDelayMs: 100, jitter: RetryPolicy::JITTER_NONE), sleeper: static function (int $ms) use (&$sleeps): void {
            $sleeps[] = $ms;
        });

        self::assertSame(200, $transport->send('GET', 'https://example.test/')->status);
        self::assertSame(3, $inner->calls);
        self::assertSame([100, 200], $sleeps);
    }

    public function test_it_gives_up_and_returns_the_last_response(): void
    {
        $inner = $this->scripted([500]);
        $transport = new RetryingTransport($inner, new RetryPolicy(maxAttempts: 2, baseDelayMs: 0), sleeper: static function (int $ms): void {
        });

        self::assertSame(500, $transport->send('GET', 'https://example.test/')->status);
        self::assertSame(2, $inner->calls);
    }

    public function test_it_does_not_retry_non_idempotent_methods_or_permanent_errors(): void
    {
        $post = $this->scripted([503]);
        (new RetryingTransport($post, sleeper: static function (int $ms): void {
        }))->send('POST', 'https://example.test/');
        self::assertSame(1, $post->calls);

        $notFound = $this->scripted([404]);
        (new RetryingTransport($notFound, sleeper: static function (int $ms): void {
        }))->send('GET', 'https://example.test/');
        self::assertSame(1, $notFound->calls);
    }

    public function test_it_honors_retry_after_up_to_the_cap(): void
    {
        $sleeps = [];
        $transport = new RetryingTransport(
            $this->scripted([429, 200], ['Retry-After' => ['7']]),
            new RetryPolicy(baseDelayMs: 100, maxDelayMs: 5_000, jitter: RetryPolicy::JITTER_NONE),
            sleeper: static function (int $ms) use (&$sleeps): void {
                $sleeps[] = $ms;
            },
        );
        $transport->send('GET', 'https://example.test/');

        self::assertSame([5_000], $sleeps);
    }

    public function test_it_retries_network_errors_then_rethrows(): void
    {
        $error = new ConnectException('boom', new Request('GET', 'https://example.test/'));
        $inner = $this->scripted([$error]);
        $transport = new RetryingTransport($inner, new RetryPolicy(maxAttempts: 2, baseDelayMs: 0), sleeper: static function (int $ms): void {
        });

        try {
            $transport->send('GET', 'https://example.test/');
            self::fail('Expected the network error to propagate.');
        } catch (ConnectException) {
            self::assertSame(2, $inner->calls);
        }
    }
}
