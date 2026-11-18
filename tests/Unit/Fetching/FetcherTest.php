<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Fetching;

use HElturkey\WebTrawler\Contracts\Http\TransportInterface;
use HElturkey\WebTrawler\Response\FetchResponse;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\TestCase;

final class FetcherTest extends TestCase
{
    public function test_scraper_uses_the_injected_transport(): void
    {
        $transport = new class () implements TransportInterface {
            public array $receivedOptions = [];

            public function send(string $method, string $url, array $options = []): FetchResponse
            {
                $this->receivedOptions = $options;

                return new FetchResponse(200, ['Content-Type' => ['text/html']], '<html><head><title>Fetched</title></head><body><h1>Ready</h1></body></html>', $url, $url, 1.0);
            }
        };

        $scraper = Scraper::create($transport, new InMemorySnapshotStore());
        $page = $scraper->get('https://example.test');

        self::assertSame('Fetched', $page->title());
        self::assertSame('Ready', $page->css('h1')->first()?->text());
        self::assertSame(false, $transport->receivedOptions['http_errors']);
    }

    public function test_it_rejects_non_http_schemes_before_transport(): void
    {
        $transport = new class () implements TransportInterface {
            public function send(string $method, string $url, array $options = []): FetchResponse
            {
                self::fail('Transport must not be called for unsupported URL schemes.');
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        Scraper::create($transport)->get('file:///etc/passwd');
    }
}
