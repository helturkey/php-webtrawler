<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Spider;

use HElturkey\WebTrawler\Contracts\BatchCrawlDeduplicator;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Storage\SQLiteCrawlDeduplicator;
use HElturkey\WebTrawler\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

final class BatchFrontierTest extends TestCase
{
    private function transport(): RecordingTransport
    {
        $links = '<a href="/1">1</a><a href="/2">2</a><a href="/1">1 again</a><a href="/3">3</a><a href="/">home</a>';

        return new RecordingTransport([
            'https://site.test/' => [200, $links],
            'https://site.test/1' => [200, $links],
            'https://site.test/2' => [200, $links],
            'https://site.test/3' => [200, $links],
        ]);
    }

    private function spider(int $maxPages = 100): Spider
    {
        return new class ($maxPages) extends Spider {
            public function __construct(int $maxPages)
            {
                $this->maxPages = $maxPages;
            }

            public function startRequests(): iterable
            {
                yield SpiderRequest::get('https://site.test/');
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                foreach ($page->css('a[href]') as $link) {
                    yield SpiderRequest::get((string) $link->attr('href'));
                }
            }
        };
    }

    public function test_the_sqlite_deduplicator_advertises_batch_support(): void
    {
        self::assertTrue(is_subclass_of(SQLiteCrawlDeduplicator::class, BatchCrawlDeduplicator::class));
    }

    public function test_batched_deduplication_crawls_each_url_exactly_once(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not installed.');
        }

        $transport = $this->transport();
        $result = Scraper::create($transport)->crawl($this->spider(), crawlDeduplicator: new SQLiteCrawlDeduplicator(':memory:'));

        $urls = $transport->urls();
        sort($urls);
        self::assertSame(['https://site.test/', 'https://site.test/1', 'https://site.test/2', 'https://site.test/3'], $urls);
        self::assertSame(4, $result->requestsScheduled);
        self::assertSame(4, $result->requestsCompleted);
    }

    public function test_the_batched_and_in_memory_paths_visit_the_same_pages_in_the_same_order(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not installed.');
        }

        $memory = $this->transport();
        Scraper::create($memory)->crawl($this->spider());
        $batched = $this->transport();
        Scraper::create($batched)->crawl($this->spider(), crawlDeduplicator: new SQLiteCrawlDeduplicator(':memory:'));

        self::assertSame($memory->urls(), $batched->urls());
    }

    public function test_the_page_budget_is_respected_when_a_batch_is_larger_than_the_budget(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not installed.');
        }

        $transport = $this->transport();
        $result = Scraper::create($transport)->crawl($this->spider(maxPages: 3), crawlDeduplicator: new SQLiteCrawlDeduplicator(':memory:'));

        self::assertSame(3, $result->requestsScheduled);
        self::assertCount(3, $transport->calls);
    }
}
