<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Contracts\HttpTransport;
use HElturkey\WebTrawler\Http\FetchResponse;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Spider\CrawlResult;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Spider\SpiderRunner;
use HElturkey\WebTrawler\Http\Fetcher;
use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\TestCase;

final class SpiderRunnerRobustnessTest extends TestCase
{
    /** @var list<string> */
    private array $log = [];

    /** @param array<string, array{int, string}> $map */
    private function transport(array $map): HttpTransport
    {
        $log = &$this->log;

        return new class ($map, $log) implements HttpTransport {
            /** @param array<string, array{int, string}> $map @param list<string> $log */
            public function __construct(private array $map, private array &$log)
            {
            }

            public function send(string $method, string $url, array $options = []): FetchResponse
            {
                $this->log[] = $method . ' ' . $url . (isset($options['form_params']) ? ' ' . json_encode($options['form_params']) : '');
                [$status, $body] = $this->map[$url] ?? [404, '<p>missing</p>'];

                return new FetchResponse($status, [], $body, $url, $url, 1.0);
            }
        };
    }

    private function crawl(Spider $spider, array $map): CrawlResult
    {
        return Scraper::create($this->transport($map), new InMemorySnapshotStore())->crawl($spider);
    }

    public function test_post_requests_with_different_payloads_are_not_deduplicated(): void
    {
        $spider = new class () extends Spider {
            public function startRequests(): iterable
            {
                yield SpiderRequest::post('https://example.test/search', ['page' => 1]);
                yield SpiderRequest::post('https://example.test/search', ['page' => 2]);
                yield SpiderRequest::post('https://example.test/search', ['page' => 2]);
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield ['url' => $page->url()];
            }
        };

        $result = $this->crawl($spider, ['https://example.test/search' => [200, '<p>x</p>']]);

        self::assertCount(2, $this->log);
        self::assertCount(2, $result->items);
        self::assertSame(2, $result->requestsScheduled);
    }

    public function test_bad_links_do_not_abort_the_page_and_offsite_or_non_http_links_are_skipped_quietly(): void
    {
        $spider = new class () extends Spider {
            protected array $startUrls = ['https://example.test/'];
            protected array $allowedHosts = ['example.test'];

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield ['item' => 'first'];
                yield SpiderRequest::get('mailto:someone@example.test');
                yield SpiderRequest::get('javascript:void(0)');
                yield SpiderRequest::get('https://other.test/elsewhere');
                yield SpiderRequest::get('http://:80');
                yield ['item' => 'second'];
            }
        };

        $result = $this->crawl($spider, ['https://example.test/' => [200, '<p>hi</p>']]);

        self::assertSame([['item' => 'first'], ['item' => 'second']], $result->items);
        self::assertCount(1, $this->log, 'Only the start page may be requested.');
        self::assertCount(1, $result->errors, 'Only the unparsable link is reported.');
        self::assertStringContainsString('unparsable', $result->errors[0]['message']);
    }

    public function test_error_status_pages_are_reported_not_parsed(): void
    {
        $spider = new class () extends Spider {
            protected array $startUrls = ['https://example.test/a', 'https://example.test/b'];

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield ['url' => $page->url()];
            }
        };

        $result = $this->crawl($spider, ['https://example.test/a' => [200, '<p>a</p>'], 'https://example.test/b' => [500, '<p>boom</p>']]);

        self::assertSame([['url' => 'https://example.test/a']], $result->items);
        self::assertSame(1, $result->requestsCompleted);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('500', $result->errors[0]['message']);
    }

    public function test_a_spider_can_opt_in_to_parsing_error_pages(): void
    {
        $spider = new class () extends Spider {
            protected array $startUrls = ['https://example.test/gone'];

            public function acceptsStatus(int $status): bool
            {
                return $status === 404;
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield ['title' => $page->css('p')->first()?->text()];
            }
        };

        $result = $this->crawl($spider, ['https://example.test/gone' => [404, '<p>custom 404</p>']]);

        self::assertSame([['title' => 'custom 404']], $result->items);
    }

    public function test_relative_links_resolve_against_base_href_and_the_politeness_delay_is_applied(): void
    {
        $spider = new class () extends Spider {
            protected array $startUrls = ['https://example.test/start'];
            protected int $requestDelayMs = 250;

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                if ($request->depth === 0) {
                    yield SpiderRequest::get('child');
                    yield SpiderRequest::get('child#fragment');
                }
                yield ['url' => $page->url()];
            }
        };
        $sleeps = [];
        $fetcher = new Fetcher($this->transport([
            'https://example.test/start' => [200, '<head><base href="/base/"></head><p>s</p>'],
            'https://example.test/base/child' => [200, '<p>c</p>'],
        ]), new InMemorySnapshotStore(), new AdaptiveMatcher());

        $result = (new SpiderRunner($fetcher, sleeper: static function (int $ms) use (&$sleeps): void {
            $sleeps[] = $ms;
        }, clock: static fn (): int => 0))->run($spider);

        self::assertSame(['GET https://example.test/start', 'GET https://example.test/base/child'], $this->log);
        self::assertSame([250], $sleeps, 'No delay before the first request; fragment duplicates are collapsed.');
        self::assertCount(2, $result->items);
    }

    public function test_max_pages_bounds_the_crawl(): void
    {
        $spider = new class () extends Spider {
            protected array $startUrls = ['https://example.test/0'];
            protected int $maxPages = 3;

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                $next = (int) basename($page->url()) + 1;
                yield SpiderRequest::get('/' . $next);
            }
        };
        $map = [];
        for ($i = 0; $i < 10; $i++) {
            $map["https://example.test/$i"] = [200, '<p>x</p>'];
        }

        self::assertSame(3, $this->crawl($spider, $map)->requestsCompleted);
    }
}
