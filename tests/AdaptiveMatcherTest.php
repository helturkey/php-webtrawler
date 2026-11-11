<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\TestCase;

final class AdaptiveMatcherTest extends TestCase
{
    public function test_it_recovers_an_element_after_the_dom_structure_changes(): void
    {
        $store = new InMemorySnapshotStore();
        $matcher = new AdaptiveMatcher();
        $firstPage = Page::fromHtml(
            '<html><body><div class="book-card"><img src="cover.jpg"><h2 class="book-title" data-testid="book-title">The Arabic Book</h2><span>Author</span></div></body></html>',
            'https://example.test/books',
            $store,
            $matcher,
        );
        $original = $firstPage->css('.book-title')->first();
        self::assertNotNull($original);
        $firstPage->remember('book-title', $original);

        $updatedPage = Page::fromHtml(
            '<html><body><article class="publication"><img src="cover.jpg"><section class="details"><h3 class="heading" data-testid="book-title">The Arabic Book</h3><span>Author</span></section></article></body></html>',
            'https://example.test/books',
            $store,
            $matcher,
        );

        $result = $updatedPage->adaptive('book-title')->first();

        self::assertTrue($result->accepted, $result->reason ?? 'Adaptive element should be accepted.');
        self::assertSame('The Arabic Book', $result->element()?->text());
        self::assertGreaterThanOrEqual(0.52, $result->confidence());
        self::assertArrayHasKey('data-testid', $result->bestMatch?->signals ?? []);
    }

    public function test_it_refuses_an_ambiguous_match(): void
    {
        $store = new InMemorySnapshotStore();
        $matcher = new AdaptiveMatcher();
        $source = Page::fromHtml(
            '<html><body><h2 data-testid="heading">Same title</h2></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );
        $original = $source->css('h2')->first();
        self::assertNotNull($original);
        $source->remember('heading', $original);

        $changed = Page::fromHtml(
            '<html><body><section><h2 data-testid="heading">Same title</h2><h2 data-testid="heading">Same title</h2></section></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );

        $result = $changed->adaptive('heading', minimumConfidence: 0.3, minimumMargin: 0.05)->first();

        self::assertFalse($result->accepted);
        self::assertStringContainsString('ambiguous', strtolower((string) $result->reason));
    }

    public function test_it_still_detects_ambiguity_when_only_one_candidate_is_requested(): void
    {
        $store = new InMemorySnapshotStore();
        $matcher = new AdaptiveMatcher();
        $source = Page::fromHtml(
            '<html><body><h2 data-testid="heading">Same title</h2></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );
        $original = $source->css('h2')->first();
        self::assertNotNull($original);
        $source->remember('heading', $original);

        $changed = Page::fromHtml(
            '<html><body><section><h2 data-testid="heading">Same title</h2><h2 data-testid="heading">Same title</h2></section></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );
        $snapshot = $store->get('heading');
        self::assertNotNull($snapshot);

        $result = $matcher->match($changed, $snapshot, minimumConfidence: 0.3, minimumMargin: 0.05, resultLimit: 1);

        self::assertFalse($result->accepted);
        self::assertCount(1, $result->candidates);
        self::assertStringContainsString('ambiguous', strtolower((string) $result->reason));
    }

    public function test_it_refuses_to_recover_from_a_tag_only_snapshot(): void
    {
        $store = new InMemorySnapshotStore();
        $matcher = new AdaptiveMatcher();
        $source = Page::fromHtml(
            '<html><body><div></div></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );
        $element = $source->css('div')->first();
        self::assertNotNull($element);
        $source->remember('generic-container', $element);

        $changed = Page::fromHtml(
            '<html><body><main><div></div></main></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );
        $result = $changed->adaptive('generic-container')->first();

        self::assertFalse($result->accepted);
        self::assertStringContainsString('tag-only', strtolower((string) $result->reason));
    }

    public function test_it_reports_a_missing_snapshot(): void
    {
        $store = new InMemorySnapshotStore();
        $page = Page::fromHtml('<html><body><p>Text</p></body></html>', 'https://example.test', $store, new AdaptiveMatcher());

        $result = $page->adaptive('unknown')->first();

        self::assertFalse($result->accepted);
        self::assertSame('No saved snapshot exists for this key.', $result->reason);
    }
}
