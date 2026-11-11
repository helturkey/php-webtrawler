<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Browser\BrowserResult;
use HElturkey\WebTrawler\Contracts\BrowserDriver;
use HElturkey\WebTrawler\Scraper;
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
        self::assertSame('https://example.test?rendered=1', $page->url());
        self::assertCount(2, $page->networkRequests());
        // Failed XHR/Fetch entries remain in networkRequests()/failedRequests(),
        // but apiResponses() contains completed responses only.
        self::assertCount(1, $page->apiResponses());
        self::assertSame('[{"id":1}]', $page->apiResponses()[0]['response_body']);
        self::assertSame('application/json', $page->apiResponses()[0]['response_headers']['content-type']);
        self::assertCount(1, $page->failedRequests());
        self::assertSame('net::ERR_CONNECTION_RESET', $page->failedRequests()[0]['failure']);
    }
}
