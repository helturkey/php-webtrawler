<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Http\FetchResponse;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\TestCase;

final class PageParsingTest extends TestCase
{
    public function test_it_extracts_css_xpath_and_page_metadata(): void
    {
        $store = new InMemorySnapshotStore();
        $response = new FetchResponse(200, ['Content-Type' => ['text/html']], '<html><head><title>Test Page</title></head><body><main><h1>Hello</h1><a href="/about">About</a></main></body></html>', 'https://example.test', 'https://example.test/final', 12.5);
        $page = Page::fromResponse($response, $store, new AdaptiveMatcher());

        self::assertSame('https://example.test/final', $page->url());
        self::assertSame('Test Page', $page->title());
        self::assertSame('Hello', $page->css('main h1')->first()?->text());
        self::assertSame('/about', $page->xpath('//main//a')->first()?->attr('href'));
        self::assertSame(1, $page->css('a')->count());
    }
}
