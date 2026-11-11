<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Adaptive\ElementSnapshot;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\TestCase;

/**
 * Golden scores produced by the 0.3.x matcher. The 0.4 performance work (memoized
 * structural facts, bounded candidate text) must not change adaptive scoring.
 */
final class AdaptiveEquivalenceTest extends TestCase
{
    public function test_scores_match_the_reference_implementation(): void
    {
        $before = '<html><body><main><section class="catalog"><article class="book-card" data-testid="card"><div class="meta"><h2 class="title"><a href="/books/arabic-novel?utm_source=x">The Arabic Book</a></h2><p class="desc">A novel about Cairo.</p></div></article><article class="book-card"><div class="meta"><h2 class="title"><a href="/books/other">Another Title</a></h2><p class="desc">Something else.</p></div></article></section></main></body></html>';
        $after = '<html><body><main><div class="wrapper"><section class="catalog"><article class="item" data-testid="card"><div class="meta"><div class="inner"><h3 class="css-9x8y7z heading"><a href="/books/arabic-novel">The Arabic Book</a></h3></div><p class="desc">A novel about Cairo.</p></div></article><article class="item"><div class="meta"><h3 class="css-1q2w3e heading"><a href="/books/other">Another Title</a></h3><p class="desc">Something else.</p></div></article></section></div></main></body></html>';

        $matcher = new AdaptiveMatcher();
        $store = new InMemorySnapshotStore();
        $url = 'https://example.test/books';
        $snapshot = ElementSnapshot::capture(Page::fromHtml($before, $url, $store, $matcher)->css('h2.title a')->first() ?? self::fail('fixture'), $url);

        $result = $matcher->match(Page::fromHtml($after, $url, $store, $matcher), $snapshot, 0.52, 0.045, 3);

        self::assertSame('accepted', $result->status);
        self::assertEqualsWithDelta(0.350322, (float) $result->margin, 1e-6);
        $scores = array_map(static fn ($match): array => [$match->tag(), round($match->confidence, 6)], $result->candidates);
        self::assertEqualsWithDelta(0.877327, $scores[0][1], 1e-6);
        self::assertEqualsWithDelta(0.527005, $scores[1][1], 1e-6);
        self::assertEqualsWithDelta(0.515602, $scores[2][1], 1e-6);
        self::assertSame(['a', 'div', 'h3'], array_column($scores, 0));
    }

    public function test_result_limit_is_validated_even_without_a_snapshot(): void
    {
        $page = Page::fromHtml('<p>x</p>', 'https://example.test/', new InMemorySnapshotStore(), new AdaptiveMatcher());

        $this->expectException(\InvalidArgumentException::class);
        $page->adaptive('missing')->first(0);
    }
}
