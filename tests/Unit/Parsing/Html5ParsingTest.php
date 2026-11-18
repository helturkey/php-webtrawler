<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Parsing;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Response\FetchResponse;
use HElturkey\WebTrawler\Parsing\HtmlPageParser;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\TestCase;

/** Documents must be parsed with the HTML5 algorithm, i.e. into the tree a browser builds. */
final class Html5ParsingTest extends TestCase
{
    private function page(string $html): \HElturkey\WebTrawler\Parsing\Page
    {
        $parser = new HtmlPageParser(new InMemorySnapshotStore(), new AdaptiveMatcher());

        return $parser->parse(new FetchResponse(200, ['Content-Type' => ['text/html; charset=utf-8']], $html, 'https://example.test/', 'https://example.test/', 1.0));
    }

    public function test_tables_get_the_implied_tbody_like_in_a_browser(): void
    {
        $page = $this->page('<table><tr><td>cell</td></tr></table>');

        self::assertCount(1, $page->css('table > tbody > tr > td'));
    }

    public function test_an_unclosed_paragraph_is_closed_by_the_next_block(): void
    {
        $page = $this->page('<p>first<p>second<div>third</div>');

        self::assertSame(['first', 'second'], array_map(static fn ($p): string => $p->text(), iterator_to_array($page->css('p'))));
        self::assertCount(0, $page->css('p > div'), 'A div cannot live inside a paragraph in HTML5.');
    }

    public function test_html5_elements_and_custom_elements_are_queryable(): void
    {
        $page = $this->page('<main><article><my-card data-id="7"><section>x</section></my-card></article></main>');

        self::assertSame('7', $page->css('my-card')->first()?->attr('data-id'));
        self::assertSame('x', $page->css('main article section')->first()?->text());
    }

    public function test_element_helpers_work_on_the_parsed_nodes(): void
    {
        $page = $this->page('<ul><li class="a">one</li><li class="b">two</li></ul>');
        $first = $page->css('li.a')->first();

        self::assertNotNull($first);
        self::assertSame('li', $first->tag());
        self::assertSame('ul', $first->parent()?->tag());
        self::assertCount(1, $first->siblings());
        self::assertCount(3, $page->allElements(), 'ul plus two li elements.');
        self::assertSame('one two', $page->text());
    }

    public function test_an_empty_body_still_gives_a_queryable_page(): void
    {
        $page = $this->page('');

        self::assertSame('', $page->text());
        self::assertCount(0, $page->css('p'));
    }

    public function test_base_href_still_drives_link_resolution(): void
    {
        $page = $this->page('<html><head><base href="/docs/"></head><body><a href="x">x</a></body></html>');

        self::assertSame('https://example.test/docs/x', $page->resolve('x'));
    }

    public function test_script_and_style_text_stays_out_of_page_text(): void
    {
        $page = $this->page('<body><script>var a = 1;</script><style>p{}</style><p>visible</p></body>');

        self::assertSame('visible', $page->text());
    }
}
