<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Support;

use HElturkey\WebTrawler\Browser\BrowserResult;
use HElturkey\WebTrawler\Contracts\BrowserDriver;

/** A BrowserDriver as another package would ship it: it only has to return a BrowserResult. */
final class FixedBrowserDriver implements BrowserDriver
{
    /** @var list<array{url: string, options: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(private readonly string $html = '<html><body><h1>مرحبا</h1></body></html>')
    {
    }

    public function render(string $url, array $options = []): BrowserResult
    {
        $this->calls[] = ['url' => $url, 'options' => $options];

        return new BrowserResult(
            html: $this->html,
            requestedUrl: $url,
            finalUrl: $url,
            // The server said windows-1256, but the browser already decoded the page to UTF-8 text.
            headers: ['Content-Type' => ['text/html; charset=windows-1256']],
            networkRequests: [['url' => $url . 'api', 'resource_type' => 'xhr', 'status' => 200]],
            durationMs: 5.0,
            statusCode: 200,
        );
    }
}
