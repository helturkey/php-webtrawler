<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Integration\Live\Spider;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Fetching\Fetcher;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Pipeline\JsonlAppendPipeline;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderItem;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Spider\SpiderRunner;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Storage\SQLiteCrawlDeduplicator;
use HElturkey\WebTrawler\Tests\Support\LiveTargets;
use HElturkey\WebTrawler\Tests\Support\LiveTestCase;
use HElturkey\WebTrawler\Transport\Drivers\GuzzleTransport;
use PHPUnit\Framework\Attributes\Group;

#[Group('live')]
final class LiveSpiderServicesTest extends LiveTestCase
{
    public function test_spider_runner_fetches_books_and_streams_items_to_its_pipeline(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'webtrawler-live-books-');
        self::assertNotFalse($path);
        @unlink($path);

        try {
            $fetcher = Fetcher::create(
                new GuzzleTransport(),
                new InMemorySnapshotStore(),
                new AdaptiveMatcher(),
                $this->liveOptions([LiveTargets::BOOKS_HOST]),
                enforceDefaults: true,
            );
            $pipeline = new JsonlAppendPipeline($path);
            $spider = $this->booksSpider();
            $spider->configure(['collectItems' => false]);
            $runner = new SpiderRunner($fetcher, pipelines: [$pipeline]);

            $result = $runner->run($spider);
            unset($runner, $pipeline);

            self::assertSame(1, $result->requestsCompleted);
            self::assertGreaterThan(0, $result->itemsCount);
            self::assertSame([], $result->items, 'collectItems=false should leave the in-memory item result empty.');
            self::assertFileExists($path);
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($lines);
            self::assertNotEmpty($lines);
            $firstItem = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($firstItem);
            self::assertNotEmpty($firstItem['title'] ?? null);
            self::assertNotEmpty($firstItem['url'] ?? null);
        } finally {
            @unlink($path);
        }
    }

    public function test_spider_runner_can_use_sqlite_deduplication_with_a_live_books_page(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required for the SQLite crawl deduplicator integration test.');
        }

        $database = tempnam(sys_get_temp_dir(), 'webtrawler-live-frontier-');
        self::assertNotFalse($database);

        try {
            $fetcher = Fetcher::create(
                new GuzzleTransport(),
                new InMemorySnapshotStore(),
                new AdaptiveMatcher(),
                $this->liveOptions([LiveTargets::BOOKS_HOST]),
                enforceDefaults: true,
            );
            $deduplicator = new SQLiteCrawlDeduplicator($database, expectedUrls: 100);
            $runner = new SpiderRunner($fetcher, crawlDeduplicator: $deduplicator);
            $result = $runner->run($this->booksSpider());

            self::assertSame(1, $result->requestsCompleted);
            self::assertGreaterThan(0, $result->itemsCount);
            self::assertSame([], $result->errors);
        } finally {
            @unlink($database);
            @unlink($database . '-wal');
            @unlink($database . '-shm');
        }
    }

    private function booksSpider(): Spider
    {
        return new class () extends Spider {
            protected array $startUrls = [LiveTargets::BOOKS_URL];
            protected array $allowedHosts = [LiveTargets::BOOKS_HOST];
            protected int $maxPages = 1;
            protected int $maxDepth = 0;

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                foreach ($page->css('article.product_pod') as $book) {
                    $link = $book->css('h3 a')->first();
                    $title = $link?->attr('title');
                    $href = $link?->attr('href');
                    $price = $book->css('.price_color')->first()?->text();
                    if ($title !== null && $href !== null && $price !== null) {
                        yield new SpiderItem([
                            'title' => $title,
                            'url' => $page->resolve($href),
                            'price' => $price,
                        ]);
                    }
                }
            }
        };
    }
}
