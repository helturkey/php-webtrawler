<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Integration\Live\Browser;

use HElturkey\WebTrawler\Browser\PlaywrightBrowserDriver;
use HElturkey\WebTrawler\Fetching\BrowserFetcher;
use HElturkey\WebTrawler\Parsing\HtmlPageParser;
use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Transport\Drivers\BrowserTransport;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Tests\Support\LiveTargets;
use HElturkey\WebTrawler\Tests\Support\LiveTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Live smoke tests against the public Scraping Sandbox targets. */
#[Group('browser')]
#[Group('live')]
final class RealTargetBrowserTest extends LiveTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(\Playwright\Playwright::class)) {
            self::markTestSkipped('Install the optional driver with composer require playwright-php/playwright.');
        }
    }

    public function test_it_renders_and_extracts_quotes_from_quotes_to_scrape(): void
    {
        $scraper = Scraper::create(snapshotStore: new InMemorySnapshotStore());
        $page = $scraper->fetchRendered(LiveTargets::QUOTES_URL, new PlaywrightBrowserDriver(), [
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
        $page = Scraper::create()->fetchRendered(LiveTargets::BOOKS_URL, new PlaywrightBrowserDriver(), [
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

    public function test_browser_fetcher_uses_the_same_parser_with_a_live_quote_page(): void
    {
        $parser = new HtmlPageParser(new InMemorySnapshotStore(), new AdaptiveMatcher());
        $page = (new BrowserFetcher($parser))->fetch(LiveTargets::QUOTES_URL, new PlaywrightBrowserDriver(), [
            'wait_until' => 'domcontentloaded',
            'wait_for_selector' => '.quote .text',
            'timeout' => 60_000,
            'allowed_hosts' => [LiveTargets::QUOTES_HOST],
        ]);

        self::assertGreaterThan(0, $page->css('.quote')->count());
        self::assertNotSame('', trim((string) $page->css('.quote .text')->first()?->text()));
    }

    public function test_browser_transport_can_be_used_by_the_regular_scraper_fetch_stack(): void
    {
        $transport = new BrowserTransport(new PlaywrightBrowserDriver(), [
            'wait_until' => 'domcontentloaded',
            'wait_for_selector' => 'article.product_pod h3 a',
        ]);
        $scraper = Scraper::create(transport: $transport);

        // Books to Scrape loads jQuery from ajax.googleapis.com. Keep the host
        // allow-list explicit instead of weakening the browser policy globally.
        $options = new FetchOptions(
            timeout: 45,
            connectTimeout: 15,
            maxBodyBytes: 8 * 1024 * 1024,
            blockPrivateNetworks: true,
            allowedHosts: LiveTargets::BOOKS_BROWSER_HOSTS,
        );
        $page = $scraper->get(LiveTargets::BOOKS_URL, $options);

        self::assertGreaterThan(0, $page->css('article.product_pod')->count());
        self::assertNotSame('', trim((string) $page->css('article.product_pod h3 a')->first()?->attr('title')));
    }

}
