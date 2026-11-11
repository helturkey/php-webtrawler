<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\TestCase;

final class PageRobustnessTest extends TestCase
{
    private function page(string $html, string $url = 'https://example.test/dir/'): Page
    {
        return Page::fromHtml($html, $url, new InMemorySnapshotStore(), new AdaptiveMatcher());
    }

    public function test_an_empty_body_is_queryable_without_warnings(): void
    {
        $page = $this->page('');

        self::assertNull($page->title());
        self::assertSame('', $page->text());
        self::assertTrue($page->css('a')->isEmpty());
        self::assertStringNotContainsString('<?xml', $page->html());
    }

    public function test_text_excludes_script_and_style_and_separates_blocks(): void
    {
        $page = $this->page('<html><head><style>.a{color:red}</style></head><body><p>Hello</p><p>world</p><div>a<b>b</b>c</div><script>var x=1;</script></body></html>');

        self::assertSame('Hello world abc', $page->text());
    }

    public function test_resolve_honors_a_valid_base_href_and_ignores_unsafe_ones(): void
    {
        self::assertSame('https://example.test/other/x', $this->page('<head><base href="/other/"></head>')->resolve('x'));
        self::assertSame('https://example.test/dir/x', $this->page('<head><base href="javascript:alert(1)"></head>')->resolve('x'));
        self::assertSame('https://example.test/dir/x', $this->page('<p>no base</p>')->resolve('x'));
    }
}
