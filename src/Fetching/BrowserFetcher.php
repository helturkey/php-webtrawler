<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Fetching;

use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Policy\HostPolicy;
use HElturkey\WebTrawler\Policy\UrlGuard;
use HElturkey\WebTrawler\Policy\UrlNormalizer;
use HElturkey\WebTrawler\Response\FetchResponse;
use HElturkey\WebTrawler\Contracts\BrowserDriver;
use HElturkey\WebTrawler\Contracts\ResponseParser;
use HElturkey\WebTrawler\Exception\ResponseTooLargeException;
use HElturkey\WebTrawler\Parsing\Page;

/** Renders through any BrowserDriver, then parses with the same ResponseParser as plain HTTP fetches.
 * Browser-internal requests still require driver-level or network egress controls.
 */
final readonly class BrowserFetcher
{
    public function __construct(private ResponseParser $parser)
    {
    }

    /** @param array<string, mixed> $options */
    public function fetch(string $url, BrowserDriver $driver, array $options = []): Page
    {
        // The package enforces the URL policy itself: a driver from another package may or may not do it.
        $allowedHosts = $options['allowed_hosts'] ?? [];
        $allowedHosts = is_array($allowedHosts) ? array_values(array_filter($allowedHosts, 'is_string')) : [];
        $blockPrivate = ($options['block_private_networks'] ?? false) === true;
        // The browser must receive exactly the URL that passed the policy checks. Checking one string and
        // rendering another (for example a raw "https://allowed.test\@evil.test/", which WHATWG parsers read
        // differently from RFC 3986 parsers) would let a parser differential bypass the host allow-list.
        $requestUrl = UrlNormalizer::normalize(trim($url));
        $this->assertAllowed($requestUrl, $allowedHosts, $blockPrivate);

        $result = $driver->render($requestUrl, $options);
        // Validate the final URL using its canonical form, but preserve the browser-reported URL
        // in the Page response. Canonicalization (for example, adding '/' to an empty path) is for
        // request identity and policy checks, not for rewriting response metadata.
        $finalUrl = trim($result->finalUrl);
        $normalizedFinalUrl = UrlNormalizer::normalize($finalUrl);
        $this->assertAllowed($normalizedFinalUrl, $allowedHosts, $blockPrivate);
        $maximumBytes = array_key_exists('max_body_bytes', $options) ? $options['max_body_bytes'] : FetchOptions::DEFAULT_MAX_BODY_BYTES;
        if (is_int($maximumBytes) && strlen($result->html) > $maximumBytes) {
            throw ResponseTooLargeException::forLimit($maximumBytes, $requestUrl);
        }

        $response = $result->toFetchResponse();

        return $this->parser->parse(new FetchResponse(
            status: $response->status,
            headers: $response->headers,
            body: $response->body,
            requestedUrl: $response->requestedUrl,
            effectiveUrl: $finalUrl,
            durationMs: $response->durationMs,
            meta: $response->meta,
        ));
    }

    /** @param list<string> $allowedHosts */
    private function assertAllowed(string $url, array $allowedHosts, bool $blockPrivate): void
    {
        HostPolicy::assert($url, $allowedHosts);
        if ($blockPrivate) {
            UrlGuard::assertPublic($url);
        }
    }
}
