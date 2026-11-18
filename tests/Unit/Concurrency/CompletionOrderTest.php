<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Concurrency;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Fetching\Fetcher;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Spider\SpiderRunner;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Tests\Support\ScriptedAsyncTransport;
use PHPUnit\Framework\TestCase;

/** The runner must handle whichever download finishes first, and must not wait for the oldest one. */
final class CompletionOrderTest extends TestCase
{
    /** @param list<string> $urls */
    private function spider(array $urls, int $concurrency): Spider
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
                yield ['url' => $page->url()];
            }
        };

        return $spider->configure(['concurrency' => $concurrency, 'maxPages' => 100]);
    }

    private function runner(ScriptedAsyncTransport $transport): SpiderRunner
    {
        return new SpiderRunner(Fetcher::create($transport, new InMemorySnapshotStore(), new AdaptiveMatcher()));
    }

    public function test_results_follow_completion_order_not_dispatch_order(): void
    {
        $transport = ScriptedAsyncTransport::lastInFirstOut();
        $urls = ['https://example.test/1', 'https://example.test/2', 'https://example.test/3', 'https://example.test/4'];

        $result = $this->runner($transport)->run($this->spider($urls, 4));

        self::assertSame(array_reverse($urls), $transport->completionOrder());
        self::assertSame(array_reverse($urls), array_column($result->items, 'url'));
        self::assertSame(4, $result->requestsCompleted);
    }

    public function test_a_free_slot_is_refilled_while_the_oldest_request_is_still_running(): void
    {
        $transport = ScriptedAsyncTransport::lastInFirstOut();
        $urls = array_map(static fn (int $i): string => 'https://example.test/' . $i, range(1, 5));

        $result = $this->runner($transport)->run($this->spider($urls, 2));

        // Request 1 never finishes until everything else is done: the runner must keep the window full instead.
        self::assertSame('https://example.test/1', $transport->completionOrder()[4]);
        self::assertLessThan(
            array_search('done:https://example.test/1', $transport->events, true),
            array_search('start:https://example.test/5', $transport->events, true),
        );
        self::assertSame(2, $transport->peak);
        self::assertCount(5, $result->items);
        self::assertSame([], $result->errors);
    }

    public function test_an_early_stop_cancels_the_downloads_still_in_flight(): void
    {
        $transport = ScriptedAsyncTransport::lastInFirstOut();
        $urls = array_map(static fn (int $i): string => 'https://example.test/' . $i, range(1, 4));
        $polls = 0;

        $result = $this->runner($transport)->run($this->spider($urls, 3), null, static function () use (&$polls): bool {
            return ++$polls > 1;
        });

        self::assertTrue($result->interrupted);
        self::assertSame(2, $transport->cancelled);
    }

    public function test_arbitrary_third_party_async_transports_work_through_the_scraper_facade(): void
    {
        $transport = ScriptedAsyncTransport::firstInFirstOut();
        $urls = ['https://example.test/a', 'https://example.test/b', 'https://example.test/c'];

        $result = \HElturkey\WebTrawler\Scraper::create($transport)->crawl($this->spider($urls, 3));

        self::assertSame($urls, array_column($result->items, 'url'));
        self::assertSame(3, $transport->peak);
    }
}
