<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Integration;

use HElturkey\WebTrawler\Browser\PlaywrightBrowserDriver;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Live smoke tests against the public Scraping Sandbox targets. */
#[Group('browser')]
#[Group('live')]
final class RealTargetBrowserTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Playwright\Playwright::class)) {
            self::markTestSkipped('Install the optional driver with composer require playwright-php/playwright.');
        }

        if (getenv('SCRAPER_RUN_LIVE_TESTS') !== '1') {
            self::markTestSkipped('Set SCRAPER_RUN_LIVE_TESTS=1 to run tests against public websites.');
        }
    }

    public function test_it_renders_and_extracts_quotes_from_quotes_to_scrape(): void
    {
        $scraper = Scraper::create(snapshotStore: new InMemorySnapshotStore());
        $page = $scraper->fetchRendered('https://quotes.toscrape.com/', new PlaywrightBrowserDriver(), [
            'wait_until' => 'domcontentloaded',
            'wait_for_selector' => '.quote .text',
            'timeout' => 60_000,
        ]);

        self::assertSame('Quotes to Scrape', $page->title());
        self::assertGreaterThan(0, $page->css('.quote')->count());
        self::assertNotSame('', $page->css('.quote .text')->first()?->text());
        self::assertSame(200, $page->response()?->status);

        $firstQuote = $page->css('.quote .text')->first();
        self::assertNotNull($firstQuote);
        $page->remember('first-quote', $firstQuote);
        $recovered = $page->adaptive('first-quote')->first();
        self::assertTrue($recovered->accepted, $recovered->reason ?? 'The live quote element should remain recoverable.');
    }

    public function test_it_renders_and_extracts_books_from_books_to_scrape(): void
    {
        $page = Scraper::create()->fetchRendered('https://books.toscrape.com/', new PlaywrightBrowserDriver(), [
            'wait_until' => 'domcontentloaded',
            'wait_for_selector' => 'article.product_pod h3 a',
            'timeout' => 60_000,
        ]);

        self::assertStringContainsString('Books to Scrape', (string) $page->title());
        self::assertGreaterThan(0, $page->css('article.product_pod')->count());

        $firstBook = $page->css('article.product_pod')->first();
        self::assertNotNull($firstBook);
        self::assertNotSame('', $firstBook->css('h3 a')->first()?->attr('title'));
        self::assertNotEmpty($page->networkRequests(), 'The browser should expose document and asset request metadata.');
    }
}
