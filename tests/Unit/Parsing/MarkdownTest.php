<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Parsing;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase
{
    private static function page(string $body, string $url = 'https://example.test/dir/page'): Page
    {
        return Page::fromHtml('<html><head><title>T</title><style>.x{}</style></head><body>' . $body . '</body></html>', $url, new InMemorySnapshotStore(), new AdaptiveMatcher());
    }

    public function test_headings_emphasis_links_and_images(): void
    {
        $markdown = self::page(
            '<h1>Title</h1><p>Hello <strong>bold</strong> and <em>it</em> <a href="../rel">link</a>.</p>'
            . '<h2>Sub</h2><p><img src="/img.png" alt="A logo"></p>',
        )->markdown();

        self::assertStringContainsString("# Title\n\nHello **bold** and *it* [link](https://example.test/rel).", $markdown);
        self::assertStringContainsString("## Sub\n\n![A logo](https://example.test/img.png)", $markdown);
        self::assertStringNotContainsString('.x{}', $markdown, 'Style content never leaks into the output.');
    }

    public function test_lists_nest_and_ordered_lists_honour_start(): void
    {
        $markdown = self::page('<ul><li>one</li><li>two<ul><li>nested</li></ul></li></ul><ol start="3"><li>a</li><li>b</li></ol>')->markdown();

        self::assertStringContainsString("- one\n- two", $markdown);
        self::assertStringContainsString('  - nested', $markdown);
        self::assertStringContainsString("3. a\n4. b", $markdown);
    }

    public function test_code_blocks_inline_code_and_quotes(): void
    {
        $markdown = self::page(
            '<pre><code class="language-php">echo 1;' . "\n" . 'echo ```;</code></pre><p>Use <code>a*b</code> here</p><blockquote><p>quote</p></blockquote>',
        )->markdown();

        self::assertStringContainsString("````php\necho 1;\necho ```;\n````", $markdown, 'The fence is longer than any backtick run inside.');
        self::assertStringContainsString('Use `a*b` here', $markdown);
        self::assertStringContainsString('> quote', $markdown);
    }

    public function test_whitespace_inside_code_blocks_is_preserved(): void
    {
        $markdown = self::page("<pre>line one  \n\n\n\nline two</pre><p>after</p>")->markdown();

        self::assertStringContainsString("```\nline one  \n\n\n\nline two\n```", $markdown);
        self::assertStringContainsString("```\n\nafter", $markdown);
    }

    public function test_tables_become_github_tables(): void
    {
        $markdown = self::page('<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>x|y</td></tr></tbody></table>')->markdown();

        self::assertStringContainsString("| A | B |\n| --- | --- |\n| 1 | x\\|y |", $markdown);
    }

    public function test_hidden_and_non_content_elements_are_dropped(): void
    {
        $markdown = self::page(
            '<p>visible</p><p hidden>SECRET1</p><p aria-hidden="true">SECRET2</p><div style="display: none">SECRET3</div>'
            . '<span style="color:red; visibility:hidden">SECRET4</span><input type="hidden" value="SECRET5">'
            . '<script>alert("SECRET6")</script><template>SECRET7</template><iframe>SECRET8</iframe>',
        )->markdown();

        self::assertSame('visible', $markdown);
    }

    public function test_dangerous_link_and_image_targets_are_removed(): void
    {
        $markdown = self::page(
            '<p><a href="javascript:alert(1)">click</a> <a href="data:text/html,x">data</a> <a href="mailto:a@b.test">mail</a></p>'
            . '<p><img src="data:image/png;base64,AAAA" alt="x"></p>',
        )->markdown();

        self::assertStringContainsString('click data [mail](mailto:a@b.test)', $markdown);
        self::assertStringNotContainsString('javascript:', $markdown);
        self::assertStringNotContainsString('data:', $markdown);
    }

    public function test_markdown_syntax_in_text_is_escaped(): void
    {
        $markdown = self::page('<p>2 * 3 _x_ [y] &lt;b&gt;</p>')->markdown();

        self::assertStringContainsString('2 \* 3 \_x\_ \[y\] \<b\>', $markdown);
    }

    public function test_link_destinations_cannot_break_out(): void
    {
        $markdown = self::page('<a href="https://example.test/a b(c)">x</a>')->markdown();

        self::assertStringContainsString('[x](https://example.test/a%20b%28c%29)', $markdown);
    }

    public function test_selector_and_main_content_modes(): void
    {
        $page = self::page('<nav>NAVTEXT</nav><main><p>Main text</p></main><div class="card">One</div><div class="card">Two</div><footer>FOOTTEXT</footer>');

        self::assertSame("One\n\nTwo", $page->markdown('.card'));
        self::assertSame('Main text', $page->markdown(mainContent: true));
        self::assertStringContainsString('NAVTEXT', $page->markdown());

        $noMain = self::page('<nav>NAVTEXT</nav><div>Body</div><footer>FOOTTEXT</footer>');
        self::assertSame('Body', $noMain->markdown(mainContent: true));
    }

    public function test_a_selector_converts_the_selected_element_itself(): void
    {
        $page = self::page('<h1>Title</h1><ul><li>one</li><li>two</li></ul>');

        self::assertSame('# Title', $page->markdown('h1'));
        self::assertSame("- one\n- two", $page->markdown('ul'));
    }

    public function test_an_element_converts_itself(): void
    {
        $page = self::page('<div id="a"><h3>Part</h3><p>Text</p></div>');

        self::assertSame("### Part\n\nText", $page->css('#a')->first()?->markdown());
    }

    public function test_pathologically_deep_documents_do_not_overflow_the_stack(): void
    {
        $html = str_repeat('<div>', 300) . 'deep' . str_repeat('</div>', 300);

        self::assertStringContainsString('deep', self::page($html)->markdown());
    }
}
