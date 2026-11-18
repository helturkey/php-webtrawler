<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Spider;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Spider\LinkExtractor;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LinkExtractorTest extends TestCase
{
    private const HTML = <<<'HTML'
<html><body>
<nav><a href="/home">Home</a></nav>
<main>
  <a href="/product/1#reviews">One</a>
  <a href="/product/1">One again</a>
  <a href="product/2">Two</a>
  <a href="/files/manual.pdf">Manual</a>
  <a href="https://other.example/page">Elsewhere</a>
  <a href="mailto:hi@example.test">Mail</a>
  <a href="javascript:void(0)">Script</a>
  <a href="#top">Top</a>
  <a href="/item/9" rel="nofollow noopener">Skip me</a>
  <area href="/map/1" shape="rect" coords="0,0,1,1">
</main>
</body></html>
HTML;

    private function page(): Page
    {
        return Page::fromHtml(self::HTML, 'https://shop.test/list/', new InMemorySnapshotStore(), new AdaptiveMatcher());
    }

    public function test_it_returns_unique_absolute_http_links_without_fragments(): void
    {
        $links = (new LinkExtractor())->extract($this->page());

        self::assertSame([
            'https://shop.test/home',
            'https://shop.test/product/1',
            'https://shop.test/list/product/2',
            'https://other.example/page',
            'https://shop.test/item/9',
            'https://shop.test/map/1',
        ], $links, 'PDF is denied by default; mailto/javascript/fragment-only links never appear.');
    }

    public function test_allow_deny_and_domain_filters(): void
    {
        $page = $this->page();

        self::assertSame(['https://shop.test/product/1', 'https://shop.test/list/product/2'], (new LinkExtractor(allow: ['#/product/#']))->extract($page));
        self::assertSame(['https://shop.test/product/1'], (new LinkExtractor(allow: ['#/product/#'], deny: ['#/2$#']))->extract($page));
        self::assertSame(['https://other.example/page'], (new LinkExtractor(allowDomains: ['other.example']))->extract($page));
        self::assertNotContains('https://other.example/page', (new LinkExtractor(denyDomains: ['other.example']))->extract($page));
    }

    public function test_subdomains_match_an_allowed_domain_but_lookalikes_do_not(): void
    {
        $extractor = new LinkExtractor(allowDomains: ['example.com']);

        self::assertTrue($extractor->matches('https://www.example.com/a'));
        self::assertTrue($extractor->matches('https://example.com/a'));
        self::assertFalse($extractor->matches('https://badexample.com/a'));
        self::assertFalse($extractor->matches('ftp://example.com/a'));
    }

    public function test_extensions_can_be_customised(): void
    {
        self::assertContains('https://shop.test/files/manual.pdf', (new LinkExtractor(denyExtensions: []))->extract($this->page()));
        self::assertFalse((new LinkExtractor(denyExtensions: ['pdf']))->matches('https://shop.test/a.PDF'));
    }

    public function test_restrict_css_limits_where_links_are_discovered(): void
    {
        $links = (new LinkExtractor(restrictCss: 'nav'))->extract($this->page());

        self::assertSame(['https://shop.test/home'], $links);
    }

    public function test_nofollow_links_can_be_skipped(): void
    {
        self::assertNotContains('https://shop.test/item/9', (new LinkExtractor(skipNofollow: true))->extract($this->page()));
    }

    public function test_the_limit_caps_the_result(): void
    {
        self::assertCount(2, (new LinkExtractor(limit: 2))->extract($this->page()));
    }

    public function test_the_page_s_own_url_is_not_returned(): void
    {
        $page = Page::fromHtml('<a href="/list/#x">self</a><a href="/list/">self2</a>', 'https://shop.test/list/', new InMemorySnapshotStore(), new AdaptiveMatcher());

        self::assertSame([], (new LinkExtractor())->extract($page));
    }

    public function test_invalid_configuration_is_rejected_up_front(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LinkExtractor(allow: ['#(unclosed#']);
    }

    public function test_tags_and_attributes_must_be_plain_names(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LinkExtractor(tags: ['a, script']);
    }
}
