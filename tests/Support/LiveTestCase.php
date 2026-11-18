<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Support;

use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Parsing\Page;
use PHPUnit\Framework\TestCase;

abstract class LiveTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('SCRAPER_RUN_LIVE_TESTS') !== '1') {
            self::markTestSkipped('Set SCRAPER_RUN_LIVE_TESTS=1 to run tests against quotes.toscrape.com and books.toscrape.com.');
        }
    }

    /** @param list<string> $allowedHosts */
    protected function liveOptions(array $allowedHosts): FetchOptions
    {
        return new FetchOptions(
            timeout: 45,
            connectTimeout: 15,
            maxBodyBytes: 8 * 1024 * 1024,
            allowedHosts: $allowedHosts,
        );
    }

    protected function assertQuotesPage(Page $page): void
    {
        self::assertSame(200, $page->response()?->status);
        self::assertGreaterThan(0, $page->css('.quote')->count(), 'The live quotes page should contain at least one quote.');
        self::assertNotSame('', trim((string) $page->css('.quote .text')->first()?->text()));
        self::assertNotSame('', trim((string) $page->css('.quote .author')->first()?->text()));
    }

    protected function assertBooksPage(Page $page): void
    {
        self::assertSame(200, $page->response()?->status);
        self::assertGreaterThan(0, $page->css('article.product_pod')->count(), 'The live books page should contain at least one book.');
        self::assertNotSame('', trim((string) $page->css('article.product_pod h3 a')->first()?->attr('title')));
        self::assertNotSame('', trim((string) $page->css('article.product_pod .price_color')->first()?->text()));
    }

    /** @return list<array{text: string, author: string}> */
    protected function extractQuotes(Page $page): array
    {
        $quotes = [];
        foreach ($page->css('.quote') as $quote) {
            $text = $quote->css('.text')->first()?->text();
            $author = $quote->css('.author')->first()?->text();
            if ($text !== null && $author !== null) {
                $quotes[] = ['text' => $text, 'author' => $author];
            }
        }

        return $quotes;
    }

    /** @return list<array{title: string, url: string, price: string}> */
    protected function extractBooks(Page $page): array
    {
        $books = [];
        foreach ($page->css('article.product_pod') as $book) {
            $link = $book->css('h3 a')->first();
            $title = $link?->attr('title');
            $href = $link?->attr('href');
            $price = $book->css('.price_color')->first()?->text();
            if ($title !== null && $href !== null && $price !== null) {
                $books[] = [
                    'title' => $title,
                    'url' => $page->resolve($href),
                    'price' => $price,
                ];
            }
        }

        return $books;
    }
}
