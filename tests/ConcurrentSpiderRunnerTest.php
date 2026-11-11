<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Contracts\AsyncHttpTransport;
use HElturkey\WebTrawler\Contracts\DownloaderMiddleware;
use HElturkey\WebTrawler\Contracts\HttpTransport;
use HElturkey\WebTrawler\Http\AutoThrottle;
use HElturkey\WebTrawler\Http\FetchOptions;
use HElturkey\WebTrawler\Http\FetchResponse;
use HElturkey\WebTrawler\Http\Fetcher;
use HElturkey\WebTrawler\Http\GuzzleTransport;
use HElturkey\WebTrawler\Http\RetryPolicy;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Spider\JsonCrawlStateStore;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Spider\SpiderRunner;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\TestCase;

final class ConcurrentSpiderRunnerTest extends TestCase
{
    /** @param list<Response> $responses */
    private function guzzle(array $responses): GuzzleTransport
    {
        return new GuzzleTransport(new Client(['handler' => HandlerStack::create(new MockHandler($responses)), 'http_errors' => false]));
    }

    private function fetcher(HttpTransport $transport): Fetcher
    {
        return new Fetcher($transport, new InMemorySnapshotStore(), new AdaptiveMatcher());
    }

    /** @param list<string> $urls */
    private function spider(array $urls, int $concurrency = 1, ?RetryPolicy $retry = null, ?AutoThrottle $throttle = null, int $maxPages = 100): Spider
    {
        $spider = new class ($urls) extends Spider {
            /** @param list<string> $urls */
            public function __construct(private array $urls)
            {
            }

            public function startRequests(): iterable
            {
                foreach ($this->urls as $url) {
                    yield SpiderRequest::get($url);
                }
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield ['url' => $page->url(), 'text' => $page->text()];
            }
        };

        return $spider->configure(['concurrency' => $concurrency, 'retryPolicy' => $retry, 'autoThrottle' => $throttle, 'maxPages' => $maxPages]);
    }

    public function test_concurrency_is_bounded_and_every_page_is_processed_once(): void
    {
        $inner = $this->guzzle(array_map(static fn (int $i): Response => new Response(200, [], "<p>page $i</p>"), range(1, 7)));
        $tracker = new class ($inner) implements AsyncHttpTransport {
            public int $inFlight = 0;
            public int $peak = 0;

            public function __construct(private readonly GuzzleTransport $inner)
            {
            }

            public function supportsAsync(): bool
            {
                return true;
            }

            public function send(string $method, string $url, array $options = []): FetchResponse
            {
                return $this->inner->send($method, $url, $options);
            }

            public function sendAsync(string $method, string $url, array $options = []): PromiseInterface
            {
                $this->peak = max($this->peak, ++$this->inFlight);

                return $this->inner->sendAsync($method, $url, $options)->then(function (FetchResponse $response): FetchResponse {
                    $this->inFlight--;

                    return $response;
                });
            }
        };

        $urls = array_map(static fn (int $i): string => "https://example.test/$i", range(1, 7));
        $result = (new SpiderRunner($this->fetcher($tracker)))->run($this->spider($urls, concurrency: 3));

        self::assertSame(3, $tracker->peak, 'Never more than `concurrency` requests in flight, and the window is actually used.');
        self::assertCount(7, $result->items);
        self::assertCount(7, array_unique(array_column($result->items, 'url')));
        self::assertSame([], $result->errors);
        self::assertSame(7, $result->requestsCompleted);
    }

    public function test_concurrency_requires_an_async_capable_transport(): void
    {
        $blocking = new class () implements HttpTransport {
            public function send(string $method, string $url, array $options = []): FetchResponse
            {
                return new FetchResponse(200, [], '<p>x</p>', $url, $url, 1.0);
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/async-capable/');
        (new SpiderRunner($this->fetcher($blocking)))->run($this->spider(['https://example.test/'], concurrency: 2));
    }

    public function test_retries_back_off_without_real_sleeping_and_recover(): void
    {
        $transport = $this->guzzle([new Response(503), new Response(429, ['Retry-After' => '1']), new Response(200, [], '<p>finally</p>')]);
        $now = 0;
        $sleeps = [];
        $runner = new SpiderRunner(
            $this->fetcher($transport),
            sleeper: static function (int $ms) use (&$now, &$sleeps): void {
                $sleeps[] = $ms;
                $now += $ms;
            },
            clock: static function () use (&$now): int {
                return $now;
            },
        );

        $result = $runner->run($this->spider(['https://example.test/'], concurrency: 2, retry: new RetryPolicy(maxAttempts: 4, baseDelayMs: 100, jitter: RetryPolicy::JITTER_NONE)));

        self::assertSame([['url' => 'https://example.test/', 'text' => 'finally']], $result->items);
        self::assertSame(2, $result->requestsRetried);
        self::assertSame([], $result->errors);
        self::assertSame(100, $sleeps[0]);
        self::assertSame(1000, $sleeps[1], 'Retry-After: 1 raises the second delay above the 200 ms back-off.');
    }

    public function test_exhausted_retries_report_the_final_status_once_and_notify_middleware(): void
    {
        $failures = [];
        $middleware = new class ($failures) implements DownloaderMiddleware {
            /** @param list<string> $failures */
            public function __construct(private array &$failures)
            {
            }

            public function beforeFetch(SpiderRequest $request): SpiderRequest
            {
                return $request;
            }

            public function afterFetch(SpiderRequest $request, Page $page): Page
            {
                return $page;
            }

            public function onError(SpiderRequest $request, \Throwable $exception): void
            {
                $this->failures[] = $exception->getMessage();
            }
        };

        $runner = new SpiderRunner(
            $this->fetcher($this->guzzle([new Response(503), new Response(503), new Response(503)])),
            [$middleware],
            sleeper: static function (int $ms): void {
            },
            clock: static fn (): int => 0,
        );
        $result = $runner->run($this->spider(['https://example.test/'], concurrency: 2, retry: new RetryPolicy(maxAttempts: 3, baseDelayMs: 0)));

        self::assertSame([], $result->items);
        self::assertSame(2, $result->requestsRetried);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('503', $result->errors[0]['message']);
        self::assertCount(1, $failures, 'onError fires for the final failure, not for each retried attempt.');
    }

    public function test_an_endless_seed_generator_cannot_exhaust_memory(): void
    {
        $spider = new class () extends Spider {
            protected int $maxPages = 5;

            public function startRequests(): iterable
            {
                for ($i = 0; ; $i++) {
                    yield SpiderRequest::get("https://example.test/$i");
                }
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield ['url' => $page->url()];
            }
        };
        $transport = new class () implements HttpTransport {
            public function send(string $method, string $url, array $options = []): FetchResponse
            {
                return new FetchResponse(200, [], '<p>x</p>', $url, $url, 1.0);
            }
        };

        $result = (new SpiderRunner($this->fetcher($transport)))->run($spider);

        self::assertSame(5, $result->requestsScheduled);
        self::assertSame(5, $result->requestsCompleted);
    }

    public function test_auto_throttle_spaces_requests_to_the_same_host_and_adapts(): void
    {
        $transport = $this->guzzle([new Response(200, [], '<p>1</p>'), new Response(200, [], '<p>2</p>'), new Response(200, [], '<p>3</p>')]);
        $sleeps = [];
        $runner = new SpiderRunner($this->fetcher($transport), sleeper: static function (int $ms) use (&$sleeps): void {
            $sleeps[] = $ms;
        }, clock: static fn (): int => 0);

        $runner->run($this->spider(
            ['https://example.test/1', 'https://example.test/2', 'https://other.test/1'],
            throttle: new AutoThrottle(targetConcurrency: 1.0, startDelayMs: 800, minDelayMs: 0, maxDelayMs: 5000),
        ));

        self::assertCount(1, $sleeps, 'Only the second request to example.test waits; other.test starts immediately.');
        // The first response teaches the throttle: (800 ms start delay + ~0 ms latency) / 2 ~= 400 ms.
        self::assertEqualsWithDelta(400, $sleeps[0], 25);
    }

    public function test_state_files_are_private_and_reject_foreign_spiders(): void
    {
        $path = sys_get_temp_dir() . '/scraper-unit-state-' . bin2hex(random_bytes(4)) . '.json';
        $state = new JsonCrawlStateStore($path);
        $options = new FetchOptions(headers: ['X-A' => '1'], timeout: 7.0);
        $spider = new class ($options) extends Spider {
            public function __construct(private FetchOptions $options)
            {
            }

            public function startRequests(): iterable
            {
                yield SpiderRequest::get('https://example.test/1', $this->options);
                yield SpiderRequest::post('https://example.test/2', ['q' => 'x'], $this->options);
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield [];
            }
        };
        $transport = new class () implements HttpTransport {
            public function send(string $method, string $url, array $options = []): FetchResponse
            {
                return new FetchResponse(200, [], '<p>x</p>', $url, $url, 1.0);
            }
        };
        $runner = new SpiderRunner($this->fetcher($transport));

        try {
            $result = $runner->run($spider, $state, static fn (): bool => true);
            self::assertTrue($result->interrupted);

            $raw = (string) file_get_contents($path);
            self::assertStringNotContainsString('proxy', $raw);
            $data = json_decode($raw, true);
            self::assertCount(2, $data['pending']);
            self::assertSame(['q' => 'x'], $data['pending'][1]['extra']['form_params'], 'The POST body is a request extra, kept apart from the options.');
            self::assertEquals(7.0, $data['pending'][0]['options']['timeout']);
            if (DIRECTORY_SEPARATOR === '/') {
                self::assertSame(0600, fileperms($path) & 0777);
            }

            $other = new class () extends Spider {
                public function parse(Page $page, SpiderRequest $request): iterable
                {
                    return [];
                }
            };
            try {
                $runner->run($other, $state);
                self::fail('A state file belongs to one spider class.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('different spider', $exception->getMessage());
            }

            $finished = $runner->run($spider, $state);
            self::assertTrue($finished->resumed);
            self::assertSame(2, $finished->requestsCompleted);
            self::assertFalse($state->exists());
        } finally {
            @unlink($path);
        }

        file_put_contents($path, '{broken');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/corrupt/');
            $state->load();
        } finally {
            @unlink($path);
        }
    }

    public function test_explicit_proxy_options_are_rejected_by_resumable_state_instead_of_being_silently_dropped(): void
    {
        $options = new FetchOptions(proxy: 'http://user:s3cret@proxy:8080');

        try {
            $options->toState();
            self::fail('A proxy with credentials must not be silently omitted from resumable request state.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('explicit request proxy is not persisted', $exception->getMessage());
            self::assertStringNotContainsString('s3cret', $exception->getMessage());
        }
    }
}
