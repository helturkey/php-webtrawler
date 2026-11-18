<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Browser;

use HElturkey\WebTrawler\Browser\BrowserResult;
use HElturkey\WebTrawler\Contracts\BrowserDriver;
use HElturkey\WebTrawler\Exception\HostNotAllowedException;
use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Tests\Support\FixedBrowserDriver;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\TestCase;

final class BrowserFetcherTest extends TestCase
{
    public function test_it_converts_browser_output_into_a_page_and_exposes_network_metadata(): void
    {
        $driver = new class () implements BrowserDriver {
            public function render(string $url, array $options = []): BrowserResult
            {
                return new BrowserResult(
                    html: '<html><head><title>Rendered</title></head><body><main>Ready</main></body></html>',
                    requestedUrl: $url,
                    finalUrl: $url . '?rendered=1',
                    headers: ['Content-Type' => ['text/html']],
                    networkRequests: [[
                        'url' => 'https://example.test/api/books',
                        'method' => 'GET',
                        'resource_type' => 'fetch',
                        'status' => 200,
                        'request_headers' => ['accept' => 'application/json'],
                        'response_headers' => ['content-type' => 'application/json'],
                        'response_body' => '[{"id":1}]',
                    ], [
                        'url' => 'https://example.test/api/failing',
                        'method' => 'GET',
                        'resource_type' => 'fetch',
                        'status' => null,
                        'failure' => 'net::ERR_CONNECTION_RESET',
                    ]],
                    durationMs: 11.0,
                );
            }
        };

        $page = Scraper::create(snapshotStore: new InMemorySnapshotStore())
            ->fetchRendered('https://example.test', $driver);

        self::assertSame('Rendered', $page->title());
        // The browser is asked to render the canonical URL that passed the policy checks.
        self::assertSame('https://example.test/?rendered=1', $page->url());
        self::assertCount(2, $page->networkRequests());
        // Failed XHR/Fetch entries remain in networkRequests()/failedRequests(),
        // but apiResponses() contains completed responses only.
        self::assertCount(1, $page->apiResponses());
        self::assertSame('[{"id":1}]', $page->apiResponses()[0]['response_body']);
        self::assertSame('application/json', $page->apiResponses()[0]['response_headers']['content-type']);
        self::assertCount(1, $page->failedRequests());
        self::assertSame('net::ERR_CONNECTION_RESET', $page->failedRequests()[0]['failure']);
    }

    public function test_the_driver_receives_the_canonical_url_that_was_checked(): void
    {
        $driver = new FixedBrowserDriver();
        Scraper::create()->fetchRendered('HTTPS://Example.TEST:443/a/./b/../c', $driver);

        self::assertSame(['https://example.test/a/c'], array_column($driver->calls, 'url'));
    }

    public function test_the_checked_url_and_the_rendered_url_always_name_the_same_allowed_host(): void
    {
        $scraper = Scraper::create(
            defaultOptions: new FetchOptions(allowedHosts: ['allowed.test']),
            enforceDefaultSecurity: true,
        );

        // URL parsers disagree about a backslash before "@". Whatever the normalizer decides, the string
        // handed to the browser must be the one that was validated: it is either refused outright or it
        // targets the allow-listed host, never a different host than the one that was checked.
        foreach (['https://allowed.test\\@evil.test/', 'https://evil.test\\@allowed.test/', 'https://allowed.test%2f@evil.test/'] as $hostile) {
            $driver = new FixedBrowserDriver();
            try {
                $scraper->fetchRendered($hostile, $driver);
            } catch (HostNotAllowedException | \InvalidArgumentException) {
                self::assertSame([], $driver->calls, 'A refused URL must never reach the driver.');
                continue;
            }

            self::assertCount(1, $driver->calls);
            $host = parse_url($driver->calls[0]['url'], PHP_URL_HOST);
            self::assertSame('allowed.test', $host, 'The driver was given a URL whose host differs from the validated one.');
        }
    }
}
