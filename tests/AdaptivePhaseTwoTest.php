<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Adaptive\AdaptiveScoringProfile;
use HElturkey\WebTrawler\Adaptive\ElementSnapshot;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\TestCase;

final class AdaptivePhaseTwoTest extends TestCase
{
    public function test_it_ignores_volatile_ids_and_generated_css_classes(): void
    {
        $store = new InMemorySnapshotStore();
        $matcher = new AdaptiveMatcher();
        $page = Page::fromHtml(
            '<html><body><h2 id=":r17:" class="book-title css-a83jd1">Book title</h2></body></html>',
            'https://example.test/books',
            $store,
            $matcher,
        );
        $element = $page->css('h2')->first();
        self::assertNotNull($element);

        $page->remember('book-title', $element);
        $snapshot = $store->get('book-title');

        self::assertNotNull($snapshot);
        self::assertSame(2, $snapshot->schemaVersion);
        self::assertArrayNotHasKey('id', $snapshot->attributes);
        self::assertSame(['book-title'], $snapshot->classes);
    }

    public function test_it_filters_css_module_hash_suffixes(): void
    {
        $store = new InMemorySnapshotStore();
        $matcher = new AdaptiveMatcher();
        $source = Page::fromHtml(
            '<html><body><h2 class="Book_title__a1b2c3">Book title</h2></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );
        $title = $source->css('h2')->first();
        self::assertNotNull($title);
        $source->remember('css-module-title', $title);
        $snapshot = $store->get('css-module-title');

        self::assertNotNull($snapshot);
        self::assertSame([], $snapshot->classes);

        $current = Page::fromHtml(
            '<html><body><h2 class="Book_title__d4e5f6">Book title</h2></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );
        $result = $current->adaptive('css-module-title')->first();

        self::assertTrue($result->accepted, $result->reason ?? 'CSS module hash churn should not prevent recovery.');
    }

    public function test_it_canonicalizes_relative_urls_and_tracking_parameters(): void
    {
        $store = new InMemorySnapshotStore();
        $matcher = new AdaptiveMatcher();
        $source = Page::fromHtml(
            '<html><body><a class="book-link" href="/books/42?utm_source=old&ref=listing">The Arabic Book</a></body></html>',
            'https://example.test/books',
            $store,
            $matcher,
        );
        $link = $source->css('a')->first();
        self::assertNotNull($link);
        $source->remember('book-link', $link);

        $current = Page::fromHtml(
            '<html><body><main><a class="new-link" href="https://example.test/books/42?ref=listing&utm_source=new">The Arabic Book</a></main></body></html>',
            'https://example.test/books',
            $store,
            $matcher,
        );
        $result = $current->adaptive('book-link')->first();

        self::assertTrue($result->accepted, $result->reason ?? 'Canonical URL should match.');
        self::assertSame(1.0, $result->bestMatch?->signals['href'] ?? null);
    }

    public function test_it_does_not_persist_sensitive_url_parameters_or_data_attributes(): void
    {
        $store = new InMemorySnapshotStore();
        $matcher = new AdaptiveMatcher();
        $source = Page::fromHtml(
            '<html><body><a href="/download?file=book.pdf&access_token=top-secret" data-api-key="private-key" data-product-id="book-42">Download book</a></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );
        $link = $source->css('a')->first();
        self::assertNotNull($link);
        $source->remember('download', $link);
        $snapshot = $store->get('download');

        self::assertNotNull($snapshot);
        // The link is still remembered (it keeps its matching signal), but the secret value is never stored.
        self::assertSame('/download?file=book.pdf&access_token=[REDACTED]', $snapshot->attributes['href'] ?? null);
        self::assertStringNotContainsString('top-secret', (string) json_encode($snapshot->toArray()));
        self::assertArrayNotHasKey('data-api-key', $snapshot->attributes);
        self::assertSame('book-42', $snapshot->attributes['data-product-id'] ?? null);
    }

    public function test_it_uses_direct_text_and_accessible_name_as_distinct_signals(): void
    {
        $store = new InMemorySnapshotStore();
        $matcher = new AdaptiveMatcher();
        $source = Page::fromHtml(
            '<html><body><button aria-label="Open book details"><span>Open</span> details</button></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );
        $button = $source->css('button')->first();
        self::assertNotNull($button);
        $source->remember('details-button', $button);
        $snapshot = $store->get('details-button');

        self::assertNotNull($snapshot);
        self::assertSame('details', $snapshot->ownText);
        self::assertSame('Open book details', $snapshot->accessibleName);

        $current = Page::fromHtml(
            '<html><body><section><button aria-label="Open book details"><strong>Open</strong> details</button></section></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );
        $result = $current->adaptive('details-button')->first();

        self::assertTrue($result->accepted, $result->reason ?? 'Accessible and direct text should identify the button.');
        self::assertArrayHasKey('own_text', $result->bestMatch?->signals ?? []);
        // aria-label itself is already scored as an attribute; accessible_name must not double-count it.
        self::assertArrayNotHasKey('accessible_name', $result->bestMatch?->signals ?? []);
    }

    public function test_it_can_customize_signal_weights(): void
    {
        $store = new InMemorySnapshotStore();
        $profile = new AdaptiveScoringProfile([
            'data-testid' => 2.0,
            'text' => 0.01,
        ]);
        $matcher = new AdaptiveMatcher(profile: $profile);
        $source = Page::fromHtml(
            '<html><body><h2 data-testid="stable-title">Original title</h2></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );
        $original = $source->css('h2')->first();
        self::assertNotNull($original);
        $source->remember('title', $original);

        $current = Page::fromHtml(
            '<html><body><h2 data-testid="stable-title">Changed title</h2><h2 data-testid="other-title">Original title</h2></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );
        $result = $current->adaptive('title', minimumConfidence: 0.0, minimumMargin: 0.0)->first();

        self::assertTrue($result->accepted, $result->reason ?? 'A zero threshold should accept the uniquely ranked candidate.');
        self::assertSame('Changed title', $result->element()?->text());
        self::assertSame(2.0, $matcher->profile()->weight('data-testid'));
    }

    public function test_it_refuses_to_accept_a_result_when_the_candidate_scan_is_truncated(): void
    {
        $store = new InMemorySnapshotStore();
        $matcher = new AdaptiveMatcher(maxCandidates: 1);
        $source = Page::fromHtml(
            '<html><body><h2 data-testid="title">Book title</h2></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );
        $title = $source->css('h2')->first();
        self::assertNotNull($title);
        $source->remember('title', $title);

        $current = Page::fromHtml(
            '<html><body><main><h2 data-testid="title">Book title</h2><p>More content</p></main></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );
        $result = $current->adaptive('title')->first();

        self::assertFalse($result->accepted);
        self::assertSame('candidate_limit', $result->status);
        self::assertTrue($result->candidateSetTruncated);
        self::assertSame(1, $result->inspectedCandidates);
        self::assertNotNull($result->bestMatch);
    }

    public function test_it_recovers_arabic_text_when_a_suffix_is_added(): void
    {
        $store = new InMemorySnapshotStore();
        $matcher = new AdaptiveMatcher();
        $source = Page::fromHtml(
            '<html><body><h2 data-testid="poem-title">موسوعة الشعراء</h2></body></html>',
            'https://example.test/poems',
            $store,
            $matcher,
        );
        $title = $source->css('h2')->first();
        self::assertNotNull($title);
        $source->remember('poem-title', $title);

        $current = Page::fromHtml(
            '<html><body><article><h3 data-testid="poem-title">موسوعة الشعراء - طبعة جديدة</h3></article></body></html>',
            'https://example.test/poems',
            $store,
            $matcher,
        );
        $result = $current->adaptive('poem-title')->first();

        self::assertTrue($result->accepted, $result->reason ?? 'The Arabic title should remain recoverable after a suffix is added.');
        self::assertSame('h3', $result->bestMatch?->tag());
    }

    public function test_it_reads_legacy_schema_one_snapshots(): void
    {
        $snapshot = ElementSnapshot::fromArray([
            'schema_version' => 1,
            'tag' => 'H2',
            'text' => 'Legacy title',
            'attributes' => ['data-testid' => 'legacy-title'],
            'classes' => ['title'],
            'parent' => ['tag' => 'div', 'id' => '', 'classes' => ['card']],
            'ancestors' => [],
            'siblings' => [],
            'same_tag_position' => 1,
            'source_url' => 'https://example.test',
            'created_at' => 123,
        ]);

        self::assertSame(1, $snapshot->schemaVersion);
        self::assertSame('h2', $snapshot->tag);
        self::assertSame('', $snapshot->ownText);
        self::assertSame('', $snapshot->accessibleName);
    }

    public function test_it_exposes_contributions_and_candidate_margin(): void
    {
        $store = new InMemorySnapshotStore();
        $matcher = new AdaptiveMatcher();
        $source = Page::fromHtml(
            '<html><body><h2 data-testid="title">Book title</h2></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );
        $title = $source->css('h2')->first();
        self::assertNotNull($title);
        $source->remember('title', $title);

        $current = Page::fromHtml(
            '<html><body><main><h2 data-testid="title">Book title</h2><p>Different content</p></main></body></html>',
            'https://example.test',
            $store,
            $matcher,
        );
        $result = $current->adaptive('title')->first();

        self::assertSame('accepted', $result->status);
        self::assertGreaterThan(0, $result->inspectedCandidates);
        self::assertNotNull($result->bestMatch?->contributions);
        self::assertGreaterThan(0.0, array_sum($result->bestMatch?->contributions ?? []));
    }
}
