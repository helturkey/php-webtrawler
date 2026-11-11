<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Contracts\HttpTransport;
use HElturkey\WebTrawler\Contracts\ItemPipeline;
use HElturkey\WebTrawler\Http\FetchResponse;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderItem;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\TestCase;

final class SpiderRunnerTest extends TestCase
{
    public function test_it_crawls_links_and_runs_item_pipelines(): void
    {
        $transport = new class () implements HttpTransport {
            public function send(string $method, string $url, array $options = []): FetchResponse
            {
                $body = str_ends_with($url, '/start')
                    ? '<html><body><h1>Start</h1></body></html>'
                    : '<html><body><h1>Detail</h1></body></html>';

                return new FetchResponse(200, [], $body, $url, $url, 1.0);
            }
        };
        $spider = new class () extends Spider {
            protected array $startUrls = ['https://example.test/start'];
            protected array $allowedHosts = ['example.test'];
            protected int $maxPages = 5;
            protected int $maxDepth = 2;

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                if (str_ends_with($request->url, '/start')) {
                    yield new SpiderItem(['title' => 'start', 'url' => $request->url]);
                    yield SpiderRequest::get('/detail');
                } else {
                    yield ['title' => 'detail', 'url' => $request->url];
                }
            }
        };
        $pipeline = new class () implements ItemPipeline {
            public function process(array $item): ?array
            {
                $item['processed'] = true;

                return $item;
            }
        };

        $result = Scraper::create($transport, new InMemorySnapshotStore())->crawl($spider, pipelines: [$pipeline]);

        self::assertCount(2, $result->items);
        self::assertCount(2, $result->visitedUrls);
        self::assertSame('https://example.test/detail', $result->items[1]['url']);
        self::assertTrue($result->items[0]['processed']);
        self::assertSame(2, $result->requestsCompleted);
        self::assertSame([], $result->errors);
    }
}
