<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Spider;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Fetching\Fetcher;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Spider\CrawlSpider;
use HElturkey\WebTrawler\Spider\LinkExtractor;
use HElturkey\WebTrawler\Spider\Rule;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Spider\SpiderRunner;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

final class CrawlSpiderTest extends TestCase
{
    private function transport(): RecordingTransport
    {
        return new RecordingTransport([
            'https://shop.test/' => [200, '<a href="/category/1">C</a><a href="/product/1">P1</a><a href="/about">About</a>'],
            'https://shop.test/category/1' => [200, '<a href="/product/2">P2</a><a href="/product/1">P1</a><a href="/category/2">C2</a>'],
            'https://shop.test/category/2' => [200, '<a href="/product/3">P3</a>'],
            'https://shop.test/product/1' => [200, '<h1>Product 1</h1><a href="/product/99">Related</a>'],
            'https://shop.test/product/2' => [200, '<h1>Product 2</h1>'],
            'https://shop.test/product/3' => [200, '<h1>Product 3</h1>'],
        ]);
    }

    private function spider(): CrawlSpider
    {
        return new class () extends CrawlSpider {
            /** @var list<string> */
            public array $started = [];

            protected array $startUrls = ['https://shop.test/'];

            protected function rules(): array
            {
                return [
                    new Rule(new LinkExtractor(allow: ['#/category/\d+#'])),
                    new Rule(new LinkExtractor(allow: ['#/product/\d+#']), 'parseProduct'),
                ];
            }

            protected function parseStart(Page $page, SpiderRequest $request): iterable
            {
                $this->started[] = $page->url();

                return [];
            }

            protected function parseProduct(Page $page, SpiderRequest $request): iterable
            {
                yield ['url' => $page->url(), 'name' => $page->css('h1')->first()?->text()];
            }
        };
    }

    public function test_rules_navigate_categories_and_extract_products(): void
    {
        $transport = $this->transport();
        $spider = $this->spider();
        $result = (new SpiderRunner(Fetcher::create($transport, new InMemorySnapshotStore(), new AdaptiveMatcher())))->run($spider);

        $names = array_column($result->items, 'name');
        sort($names);
        self::assertSame(['Product 1', 'Product 2', 'Product 3'], $names);
        self::assertSame(['https://shop.test/'], $spider->started);
        self::assertNotContains('https://shop.test/about', $transport->urls(), 'No rule accepts /about.');
        self::assertNotContains('https://shop.test/product/99', $transport->urls(), 'Leaf rules (callback, no follow) do not scan their pages for links.');
        self::assertSame([], $result->errors);
    }

    public function test_a_rule_callback_that_does_not_exist_is_reported(): void
    {
        $spider = new class () extends CrawlSpider {
            protected array $startUrls = ['https://shop.test/'];

            protected function rules(): array
            {
                return [new Rule(new LinkExtractor(allow: ['#/product/#']), 'missingCallback')];
            }
        };
        $transport = new RecordingTransport([
            'https://shop.test/' => [200, '<a href="/product/1">P</a>'],
        ]);

        $result = (new SpiderRunner(Fetcher::create($transport, new InMemorySnapshotStore(), new AdaptiveMatcher())))->run($spider);

        self::assertCount(1, $result->errors);
        self::assertStringContainsString('missingCallback', $result->errors[0]['message']);
    }

    public function test_a_callback_name_must_be_a_method_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Rule(new LinkExtractor(), 'not a method');
    }
}
