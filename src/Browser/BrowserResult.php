<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Browser;

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
}
