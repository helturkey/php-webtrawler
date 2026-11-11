<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use HElturkey\WebTrawler\Exception\ResponseTooLargeException;
use HElturkey\WebTrawler\Http\FetchOptions;
use HElturkey\WebTrawler\Http\GuzzleTransport;
use HElturkey\WebTrawler\Http\RetryPolicy;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Spider\JsonCrawlStateStore;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Tests\Support\LocalServer;
use PHPUnit\Framework\TestCase;

/** Real HTTP against a throw-away local server; skipped when the server cannot be started. */
final class LocalServerIntegrationTest extends TestCase
{
    private static ?LocalServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server = null;
    }

    private function url(string $path): string
    {
        if (self::$server === null) {
            self::markTestSkipped('Local PHP server could not be started.');
        }

        return self::$server->baseUrl . $path;
    }

    /** @return array<string, array{0: \GuzzleHttp\Handler\CurlHandler|\GuzzleHttp\Handler\StreamHandler}> */
    public static function handlers(): array
    {
        $cases = [];
        if (function_exists('curl_init')) {
            $cases['curl'] = [new CurlHandler()];
        }
        if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $cases['stream'] = [new StreamHandler()];
        }

        return $cases;
    }

    public function test_the_size_limit_aborts_chunked_downloads_under_both_guzzle_handlers(): void
    {
        $url = $this->url('/big');
        $ran = 0;
        foreach (self::handlers() as $name => [$handler]) {
            $transport = new GuzzleTransport(new Client(['handler' => HandlerStack::create($handler), 'http_errors' => false]));
            try {
                $transport->send('GET', $url, (new FetchOptions(maxBodyBytes: 1_000_000))->toTransportOptions());
                self::fail("$name: expected ResponseTooLargeException");
            } catch (ResponseTooLargeException) {
                $ran++;
            }
        }
        self::assertGreaterThan(0, $ran);
    }

    public function test_announced_oversize_and_normal_responses(): void
    {
        $scraper = Scraper::create(new GuzzleTransport());

        self::assertSame('hi', $scraper->get($this->url('/small'))->css('h1')->first()?->text());

        $this->expectException(ResponseTooLargeException::class);
        $scraper->get($this->url('/biglen'), new FetchOptions(maxBodyBytes: 1_000_000));
    }

    public function test_concurrent_downloads_overlap(): void
    {
        $base = $this->url('');
        $spider = new class ($base) extends Spider {
            protected int $concurrency = 4;

            public function __construct(private string $base)
            {
            }

            public function startRequests(): iterable
            {
                for ($i = 0; $i < 4; $i++) {
                    yield SpiderRequest::get($this->base . '/delay?ms=600&n=' . $i);
                }
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield ['n' => $page->css('p')->first()?->text()];
            }
        };

        $started = hrtime(true);
        $result = Scraper::create(new GuzzleTransport(), new InMemorySnapshotStore())->crawl($spider);
        $seconds = (hrtime(true) - $started) / 1e9;

        self::assertCount(4, $result->items);
        self::assertSame([], $result->errors);
        // Sequential would take >= 2.4 s; allow generous CI jitter but require clear overlap.
        self::assertLessThan(2.2, $seconds);
    }

    public function test_transient_failures_are_retried_without_blocking_the_crawl(): void
    {
        $url = $this->url('/flaky?k=' . bin2hex(random_bytes(4)));
        $spider = new class ($url) extends Spider {
            protected int $concurrency = 2;

            public function __construct(private string $url)
            {
            }

            public function startRequests(): iterable
            {
                yield SpiderRequest::get($this->url);
            }

            public function retryPolicy(): RetryPolicy
            {
                return new RetryPolicy(maxAttempts: 4, baseDelayMs: 50, jitter: RetryPolicy::JITTER_NONE);
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield ['text' => $page->text()];
            }
        };

        $result = Scraper::create(new GuzzleTransport())->crawl($spider);

        self::assertSame([['text' => 'ok after 2']], $result->items);
        self::assertSame(2, $result->requestsRetried);
        self::assertSame([], $result->errors);
    }

    public function test_an_interrupted_crawl_resumes_without_repeating_or_losing_pages(): void
    {
        $base = $this->url('');
        $make = static fn (): Spider => new class ($base) extends Spider {
            protected int $maxPages = 100;

            public function __construct(private string $base)
            {
            }

            public function startRequests(): iterable
            {
                yield SpiderRequest::get($this->base . '/links?i=0');
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield ['h1' => $page->css('h1')->first()?->text()];
                foreach ($page->css('a') as $link) {
                    yield SpiderRequest::get((string) $link->attr('href'));
                }
            }
        };

        $path = sys_get_temp_dir() . '/scraper-state-' . bin2hex(random_bytes(4)) . '.json';
        $state = new JsonCrawlStateStore($path);
        $scraper = Scraper::create(new GuzzleTransport());

        $stopAfter = 4;
        $first = $scraper->crawl($make(), state: $state, shouldStop: static function () use (&$stopAfter): bool {
            return $stopAfter-- <= 0;
        });
        self::assertTrue($first->interrupted);
        self::assertTrue($state->exists());
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($path) & 0777);
        }

        $second = $scraper->crawl($make(), state: $state);
        self::assertTrue($second->resumed);
        self::assertFalse($state->exists(), 'State is removed once the crawl completes.');

        $titles = array_merge(array_column($first->items, 'h1'), array_column($second->items, 'h1'));
        self::assertCount(10, $titles);
        self::assertCount(10, array_unique($titles), 'No page may be crawled twice.');
    }

    public function test_the_php_rnet_adapter_against_the_real_extension(): void
    {
        if (!\HElturkey\WebTrawler\Http\RnetTransport::isAvailable()) {
            self::markTestSkipped('php-rnet extension is not loaded.');
        }

        $scraper = Scraper::create(new \HElturkey\WebTrawler\Http\RnetTransport());

        self::assertSame('hi', $scraper->get($this->url('/small'))->css('h1')->first()?->text());
        self::assertStringEndsWith('/small', $scraper->get($this->url('/redir'))->url());
        self::assertSame('مرحبا', $scraper->get($this->url('/cp1256'))->css('h1')->first()?->text(), 'Legacy-encoded bodies go through bytes().');
        self::assertSame(500, $scraper->get($this->url('/status500'))->response()?->status);

        try {
            $scraper->get($this->url('/sleep'), new FetchOptions(timeout: 0.5));
            self::fail('Expected a timeout.');
        } catch (\HElturkey\WebTrawler\Contracts\TransientFailure $failure) {
            self::assertStringNotContainsString('127.0.0.1', $failure->getMessage(), 'Messages must not carry request URLs.');
        }
    }

    public function test_a_real_cross_host_redirect_is_refused_before_it_is_followed(): void
    {
        $scraper = Scraper::create(new GuzzleTransport());
        $url = $this->url('/redir-other-host');   // 127.0.0.1 -> localhost (a different host name)

        try {
            $scraper->get($url, new FetchOptions(allowedHosts: ['127.0.0.1']));
            self::fail('The redirect to a different host must be refused.');
        } catch (\HElturkey\WebTrawler\Exception\HostNotAllowedException $exception) {
            self::assertStringContainsString('localhost', $exception->getMessage());
        }

        self::assertSame('hi', $scraper->get($url, new FetchOptions(allowedHosts: ['127.0.0.1', 'localhost']))->css('h1')->first()?->text());
    }
}
