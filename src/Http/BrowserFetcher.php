<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Contracts\BrowserDriver;
use HElturkey\WebTrawler\Contracts\SnapshotStore;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Exception\ResponseTooLargeException;

final readonly class BrowserFetcher
{
    public function __construct(
        private SnapshotStore $snapshotStore,
        private AdaptiveMatcher $adaptiveMatcher,
    ) {
    }

    /** @param array<string, mixed> $options */
    public function fetch(string $url, BrowserDriver $driver, array $options = []): Page
    {
        $result = $driver->render($url, $options);
        $maximumBytes = array_key_exists('max_body_bytes', $options) ? $options['max_body_bytes'] : FetchOptions::DEFAULT_MAX_BODY_BYTES;
        if (is_int($maximumBytes) && strlen($result->html) > $maximumBytes) {
            throw ResponseTooLargeException::forLimit($maximumBytes, $url);
        }

        $response = new FetchResponse(
            status: $result->statusCode ?? 0,
            headers: $result->headers,
            body: $result->html,
            requestedUrl: $result->requestedUrl,
            effectiveUrl: $result->finalUrl,
            durationMs: $result->durationMs,
            meta: ['network_requests' => $result->networkRequests],
        );

        // Chromium already decoded the document; $result->html is UTF-8 text. The original
        // Content-Type header (e.g. windows-1256) must not trigger a second decoding.
        return Page::fromHtml($result->html, $result->finalUrl, $this->snapshotStore, $this->adaptiveMatcher, $response);
    }
}
