<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Spider;

use HElturkey\WebTrawler\Contracts\ItemPipeline;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Spider\CrawlResult;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

final class StreamingTest extends TestCase
{
    private function spider(): Spider
    {
        return new class () extends Spider {
            protected int $maxPages = 6;

            public function startRequests(): iterable
            {
                yield SpiderRequest::get('https://chain.test/1');
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                $number = (int) basename($page->url());
                yield ['n' => $number];
                if ($number < 5) {
                    yield SpiderRequest::get('https://chain.test/' . ($number + 1));
                }
            }
        };
    }

    public function test_items_arrive_one_by_one_and_the_result_is_the_generator_return_value(): void
    {
        $transport = new RecordingTransport();
        $generator = Scraper::create($transport)->stream($this->spider());

        $seen = [];
        foreach ($generator as $item) {
            $seen[] = $item['n'];
            // The crawl is suspended between items: the next page has not been fetched yet.
            self::assertCount($item['n'], $transport->calls);
        }

        self::assertSame([1, 2, 3, 4, 5], $seen);
        $result = $generator->getReturn();
        self::assertInstanceOf(CrawlResult::class, $result);
        self::assertSame(5, $result->itemsCount);
        self::assertSame([], $result->items, 'Streamed items are not buffered.');
    }

    public function test_pipelines_run_before_items_are_streamed(): void
    {
        $double = new class () implements ItemPipeline {
            public function process(array $item): ?array
            {
                return $item['n'] === 2 ? null : ['n' => $item['n'] * 10];
            }
        };

        $seen = [];
        foreach (Scraper::create(new RecordingTransport())->stream($this->spider(), pipelines: [$double]) as $item) {
            $seen[] = $item['n'];
        }

        self::assertSame([10, 30, 40, 50], $seen);
    }

    public function test_stopping_early_stops_the_crawl(): void
    {
        $transport = new RecordingTransport();
        $generator = Scraper::create($transport)->stream($this->spider());

        foreach ($generator as $item) {
            break;
        }
        unset($generator);

        self::assertLessThan(5, count($transport->calls));
    }

    public function test_the_original_spider_keeps_collecting_items(): void
    {
        $spider = $this->spider();
        foreach (Scraper::create(new RecordingTransport())->stream($spider) as $item) {
            unset($item);
        }

        $result = Scraper::create(new RecordingTransport())->crawl($spider);
        self::assertCount(5, $result->items, 'stream() works on a copy and does not change the spider.');
    }
}
