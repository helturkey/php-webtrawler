<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdaptiveRegressionCorpusTest extends TestCase
{
    /**
     * A small, deterministic regression corpus. These cases protect the adaptive
     * engine from accepting selectors based only on volatile styling or weak text.
     *
     * @return iterable<string, array{string, string, string, string, bool}>
     */
    public static function migrationCases(): iterable
    {
        yield 'wrapper insertion and heading tag change' => [
            '<main><div class="book-card"><h2 class="book-title" data-testid="book-title">The Arabic Book</h2></div></main>',
            '<main><article class="publication"><section><h3 class="heading" data-testid="book-title">The Arabic Book</h3></section></article></main>',
            '.book-title',
            'The Arabic Book',
            true,
        ];

        yield 'generated identifiers and CSS classes rotate' => [
            '<main><h2 id=":r17:" class="css-oldhash product-name">Product 42</h2></main>',
            '<main><section><h3 id=":r81:" class="css-newhash product-name">Product 42</h3></section></main>',
            '.product-name',
            'Product 42',
            true,
        ];

        yield 'Arabic label gains an edition suffix' => [
            '<main><h2 data-testid="poem-title">موسوعة الشعراء</h2></main>',
            '<main><article><h3 data-testid="poem-title">موسوعة الشعراء - طبعة جديدة</h3></article></main>',
            '[data-testid="poem-title"]',
            'موسوعة الشعراء - طبعة جديدة',
            true,
        ];

        yield 'link tracking parameters change' => [
            '<main><a class="book-link" href="/books/42?utm_source=old&ref=listing">The Arabic Book</a></main>',
            '<main><section><a class="new-link" href="https://example.test/books/42?ref=listing&utm_source=new">The Arabic Book</a></section></main>',
            '.book-link',
            'The Arabic Book',
            true,
        ];
    }

    #[DataProvider('migrationCases')]
    public function test_migration_cases(string $sourceHtml, string $currentHtml, string $selector, string $expectedText, bool $expectedAccepted): void
    {
        $store = new InMemorySnapshotStore();
        $matcher = new AdaptiveMatcher();
        $source = Page::fromHtml($sourceHtml, 'https://example.test/catalog', $store, $matcher);
        $element = $source->css($selector)->first();
        self::assertNotNull($element, 'The original selector must match the source fixture.');
        $source->remember('fixture-target', $element);

        $current = Page::fromHtml($currentHtml, 'https://example.test/catalog', $store, $matcher);
        $result = $current->adaptive('fixture-target')->first();

        self::assertSame($expectedAccepted, $result->accepted, $result->reason ?? 'Unexpected adaptive decision.');
        if ($expectedAccepted) {
            self::assertSame($expectedText, $result->element()?->text());
        }
    }

    public function test_it_rejects_ambiguous_candidates_in_the_regression_corpus(): void
    {
        $store = new InMemorySnapshotStore();
        $matcher = new AdaptiveMatcher();
        $source = Page::fromHtml(
            '<main><h2 data-testid="repeated">Repeated label</h2></main>',
            'https://example.test/catalog',
            $store,
            $matcher,
        );
        $element = $source->css('h2')->first();
        self::assertNotNull($element);
        $source->remember('repeated-label', $element);

        $current = Page::fromHtml(
            '<main><section><h2 data-testid="repeated">Repeated label</h2><h2 data-testid="repeated">Repeated label</h2></section></main>',
            'https://example.test/catalog',
            $store,
            $matcher,
        );
        $result = $current->adaptive('repeated-label', minimumConfidence: 0.30)->first();

        self::assertFalse($result->accepted);
        self::assertSame('ambiguous', $result->status);
        self::assertNotNull($result->margin);
    }
}
