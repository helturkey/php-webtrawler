<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Spider;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Fetching\Fetcher;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Spider\SpiderRunner;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

final class RobotsCrawlTest extends TestCase
{
    /** @param list<string> $urls */
    private function spider(array $urls, bool $respect = true, ?string $userAgent = null): Spider
    {
        return new class ($urls, $respect, $userAgent) extends Spider {
            /** @param list<string> $urls */
            public function __construct(private array $urls, bool $respect, ?string $userAgent)
            {
                $this->respectRobotsTxt = $respect;
                $this->userAgent = $userAgent;
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
    }

    private function runner(RecordingTransport $transport, ?callable $sleeper = null): SpiderRunner
    {
        return new SpiderRunner(
            Fetcher::create($transport, new InMemorySnapshotStore(), new AdaptiveMatcher()),
            sleeper: $sleeper,
            clock: static fn (): int => 1_000,
        );
    }

    public function test_disallowed_urls_are_skipped_and_reported(): void
    {
        $transport = new RecordingTransport([
            'https://example.test/robots.txt' => [200, "User-agent: *\nDisallow: /private\n"],
        ]);

        $result = $this->runner($transport)->run($this->spider(['https://example.test/public', 'https://example.test/private/x']));

        self::assertSame(['https://example.test/robots.txt', 'https://example.test/public'], $transport->urls());
        self::assertSame(1, $result->robotsBlocked);
        self::assertSame(1, $result->requestsCompleted);
        self::assertSame('RobotsDisallowed', $result->errors[0]['exception']);
    }

    public function test_robots_txt_is_ignored_unless_the_spider_asks_for_it(): void
    {
        $transport = new RecordingTransport([
            'https://example.test/robots.txt' => [200, "User-agent: *\nDisallow: /\n"],
        ]);

        $result = $this->runner($transport)->run($this->spider(['https://example.test/private'], respect: false));

        self::assertSame(['https://example.test/private'], $transport->urls());
        self::assertSame(0, $result->robotsBlocked);
    }

    public function test_an_unreachable_robots_file_blocks_the_whole_origin(): void
    {
        $transport = new RecordingTransport([
            'https://example.test/robots.txt' => [503, ''],
        ]);

        $result = $this->runner($transport)->run($this->spider(['https://example.test/a', 'https://example.test/b']));

        self::assertSame(['https://example.test/robots.txt'], $transport->urls());
        self::assertSame(2, $result->robotsBlocked);
    }

    public function test_the_crawl_delay_is_honoured_per_host(): void
    {
        $transport = new RecordingTransport([
            'https://example.test/robots.txt' => [200, "User-agent: *\nCrawl-delay: 2\n"],
        ]);
        $sleeps = [];

        $this->runner($transport, function (int $ms) use (&$sleeps): void {
            $sleeps[] = $ms;
        })->run($this->spider(['https://example.test/a', 'https://example.test/b']));

        self::assertSame([2_000], $sleeps, 'The second request to the host waits for the declared delay.');
    }

    public function test_the_spider_user_agent_selects_the_robots_group_and_is_sent_as_a_header(): void
    {
        $transport = new RecordingTransport([
            'https://example.test/robots.txt' => [200, "User-agent: testbot\nDisallow: /\n\nUser-agent: *\nDisallow:\n"],
        ]);

        $result = $this->runner($transport)->run($this->spider(['https://example.test/a'], userAgent: 'TestBot/1.0'));

        self::assertSame(1, $result->robotsBlocked);
        self::assertSame('TestBot/1.0', $transport->calls[0]['options']['headers']['User-Agent'] ?? null);
    }
}
