<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Spider;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Contracts\CrawlDeduplicator;
use HElturkey\WebTrawler\Fetching\Fetcher;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Spider\SpiderRunner;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

final class SpiderContentDeduplicationTest extends TestCase
{
    public function test_duplicate_content_is_counted_but_each_url_is_still_parsed(): void
    {
        $transport = new RecordingTransport([
            'https://example.test/first' => [200, '<p>same body</p>'],
            'https://example.test/second' => [200, '<p>same body</p>'],
        ]);
        $deduplicator = new InMemoryTestCrawlDeduplicator();
        $parsed = (object) ['count' => 0];
        $spider = new class ($parsed) extends Spider {
            public function __construct(private \stdClass $parsed)
            {
            }

            public function startRequests(): iterable
            {
                yield SpiderRequest::get('https://example.test/first');
                yield SpiderRequest::get('https://example.test/second');
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                $this->parsed->count++;

                return [];
            }
        };

        $fetcher = Fetcher::create($transport, new InMemorySnapshotStore(), new AdaptiveMatcher());
        $result = (new SpiderRunner($fetcher, crawlDeduplicator: $deduplicator))->run($spider);

        self::assertSame(1, $result->duplicateContentResponses);
        self::assertSame(2, $parsed->count, 'Identical bodies must not suppress URL-specific parsing.');
        self::assertSame(2, $result->requestsCompleted);
        self::assertTrue($deduplicator->scopeWasCleared);
    }
}

final class InMemoryTestCrawlDeduplicator implements CrawlDeduplicator
{
    /** @var array<string, array<string, true>> */
    private array $urls = [];

    /** @var array<string, array<string, string>> */
    private array $content = [];

    public bool $scopeWasCleared = false;

    public function markUrlSeen(string $scope, string $fingerprint): bool
    {
        if (isset($this->urls[$scope][$fingerprint])) {
            return true;
        }
        $this->urls[$scope][$fingerprint] = true;

        return false;
    }

    public function recordContent(string $scope, string $url, string $contentHash, int $sizeBytes): bool
    {
        $firstUrl = $this->content[$scope][$contentHash] ?? $url;
        $this->content[$scope][$contentHash] = $firstUrl;

        return $firstUrl !== $url;
    }

    public function clearScope(string $scope): void
    {
        unset($this->urls[$scope], $this->content[$scope]);
        $this->scopeWasCleared = true;
    }
}
