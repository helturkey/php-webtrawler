<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Browser;

use HElturkey\WebTrawler\Response\FetchResponse;

/**
 * What any BrowserDriver returns. Drivers from other packages build it directly; toFetchResponse() is the
 * single conversion into the response type every parser understands.
 */
final readonly class BrowserResult
{
    /**
     * @param array<string, list<string>> $headers
     * @param list<array<string, mixed>> $networkRequests Request and response metadata; sensitive headers and URL credentials are redacted.
     */
    public function __construct(
        public string $html,
        public string $requestedUrl,
        public string $finalUrl,
        public array $headers = [],
        public array $networkRequests = [],
        public float $durationMs = 0.0,
        public ?int $statusCode = null,
    ) {
    }

    /**
     * The browser already decoded the document, so the body is marked as decoded text: the original
     * Content-Type charset (for example windows-1256) must not trigger a second decoding.
     */
    public function toFetchResponse(): FetchResponse
    {
        return new FetchResponse(
            status: $this->statusCode ?? 0,
            headers: $this->headers,
            body: $this->html,
            requestedUrl: $this->requestedUrl,
            effectiveUrl: $this->finalUrl,
            durationMs: $this->durationMs,
            meta: ['network_requests' => $this->networkRequests, FetchResponse::META_DECODED => true],
        );
    }
}
