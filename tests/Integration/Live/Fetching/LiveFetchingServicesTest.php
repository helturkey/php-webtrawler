<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Integration\Live\Fetching;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Fetching\Downloader;
use HElturkey\WebTrawler\Fetching\Fetcher;
use HElturkey\WebTrawler\Parsing\HtmlPageParser;
use HElturkey\WebTrawler\Policy\RequestPolicy;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Transport\Drivers\GuzzleTransport;
use HElturkey\WebTrawler\Tests\Support\LiveTargets;
use HElturkey\WebTrawler\Tests\Support\LiveTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('live')]
final class LiveFetchingServicesTest extends LiveTestCase
{
    public function test_downloader_fetches_the_raw_books_response_without_parsing_it(): void
    {
        $options = $this->liveOptions([LiveTargets::BOOKS_HOST]);
        $downloader = new Downloader(
            new GuzzleTransport(),
            new RequestPolicy($options, enforceDefaults: true),
        );

        $response = $downloader->download(LiveTargets::BOOKS_URL);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('product_pod', $response->body);
        self::assertSame(64, strlen($response->contentHash()));
        self::assertSame(LiveTargets::BOOKS_URL, $response->requestedUrl);
    }

    public function test_fetcher_composes_downloader_and_parser_for_the_quotes_target(): void
    {
        $fetcher = Fetcher::create(
            new GuzzleTransport(),
            new InMemorySnapshotStore(),
            new AdaptiveMatcher(),
            $this->liveOptions([LiveTargets::QUOTES_HOST]),
            enforceDefaults: true,
        );

        $page = $fetcher->get(LiveTargets::QUOTES_URL);

        $this->assertQuotesPage($page);
    }

    public function test_fetcher_async_path_fetches_and_parses_live_quotes_when_curl_multi_is_available(): void
    {
        $fetcher = Fetcher::create(
            new GuzzleTransport(),
            new InMemorySnapshotStore(),
            new AdaptiveMatcher(),
            $this->liveOptions([LiveTargets::QUOTES_HOST]),
            enforceDefaults: true,
        );
        if (!$fetcher->supportsAsync()) {
            self::markTestSkipped('ext-curl with curl-multi is required for the Guzzle asynchronous live test.');
        }

        $pending = $fetcher->fetchAsync(LiveTargets::QUOTES_URL);
        $deadline = microtime(true) + 60;
        while (!$pending->isSettled() && microtime(true) < $deadline) {
            $fetcher->advance(0.1);
        }

        self::assertTrue($pending->isSettled(), 'The live asynchronous request should settle before the deadline.');
        $page = $pending->value();
        self::assertInstanceOf(\HElturkey\WebTrawler\Parsing\Page::class, $page);
        $this->assertQuotesPage($page);
    }

    public function test_scraper_entry_point_fetches_and_parses_the_books_target(): void
    {
        $scraper = Scraper::create(
            transport: new GuzzleTransport(),
            defaultOptions: $this->liveOptions(LiveTargets::hosts()),
            enforceDefaultSecurity: true,
        );

        $page = $scraper->get(LiveTargets::BOOKS_URL);

        $this->assertBooksPage($page);
    }

    public function test_session_fetches_both_example_targets_with_one_cookie_context(): void
    {
        $scraper = Scraper::create(
            transport: new GuzzleTransport(),
            defaultOptions: $this->liveOptions(LiveTargets::hosts()),
            enforceDefaultSecurity: true,
        );
        $session = $scraper->session();

        $quotes = $session->get(LiveTargets::QUOTES_URL);
        $books = $session->get(LiveTargets::BOOKS_URL);

        $this->assertQuotesPage($quotes);
        $this->assertBooksPage($books);
        self::assertInstanceOf(\GuzzleHttp\Cookie\CookieJarInterface::class, $session->cookies());
    }

    public function test_html_page_parser_parses_a_real_quotes_transport_response(): void
    {
        $options = $this->liveOptions([LiveTargets::QUOTES_HOST]);
        $response = (new GuzzleTransport())->send(
            'GET',
            LiveTargets::QUOTES_URL,
            $options->toTransportOptions(),
        );
        $parser = new HtmlPageParser(new InMemorySnapshotStore(), new AdaptiveMatcher());

        $page = $parser->parse($response, $options->encoding);

        $this->assertQuotesPage($page);
        self::assertSame(LiveTargets::QUOTES_URL, $page->url());
    }

    public function test_page_resolves_real_book_catalogue_links(): void
    {
        $page = Scraper::create()->get(LiveTargets::BOOKS_URL, $this->liveOptions([LiveTargets::BOOKS_HOST]));
        $firstHref = $page->css('article.product_pod h3 a')->first()?->attr('href');

        self::assertNotNull($firstHref);
        self::assertStringStartsWith('https://' . LiveTargets::BOOKS_HOST . '/', $page->resolve($firstHref));
    }
}
