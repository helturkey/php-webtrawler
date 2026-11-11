<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Browser;

use HElturkey\WebTrawler\Contracts\BrowserDriver;
use HElturkey\WebTrawler\Exception\UnsafeUrlException;
use HElturkey\WebTrawler\Http\HostPolicy;
use InvalidArgumentException;
use Playwright\Network\RequestInterface;
use Playwright\Network\ResponseInterface;
use Playwright\Playwright;
use RuntimeException;
use Throwable;

/**
 * Optional driver for playwright-php/playwright.
 * Install that package separately; the HTTP/parser core does not depend on it.
 */
final class PlaywrightBrowserDriver implements BrowserDriver
{
    /** @param array<string, mixed> $options */
    public function render(string $url, array $options = []): BrowserResult
    {
        self::assertHttpUrl($url);

        $blockPrivateOption = $options['block_private_networks'] ?? false;
        if (!is_bool($blockPrivateOption)) {
            throw new InvalidArgumentException('The browser block_private_networks option must be a boolean.');
        }
        $blockPrivateNetworks = $blockPrivateOption;
        $allowedHostsOption = $options['allowed_hosts'] ?? [];
        if (!is_array($allowedHostsOption) || !array_is_list($allowedHostsOption)) {
            throw new InvalidArgumentException('The browser allowed_hosts option must be a list of non-empty host names.');
        }
        $allowedHosts = [];
        foreach ($allowedHostsOption as $host) {
            if (!is_string($host)) {
                throw new InvalidArgumentException('The browser allowed_hosts option must be a list of non-empty host names.');
            }
            $host = trim($host, ". \t");
            if ($host === '') {
                throw new InvalidArgumentException('The browser allowed_hosts option must be a list of non-empty host names.');
            }
            $allowedHosts[] = $host;
        }
        $requestGuard = null;
        if ($blockPrivateNetworks || $allowedHosts !== []) {
            $requestGuard = new BrowserRequestGuard($blockPrivateNetworks, $allowedHosts);
            // Fail before launching Chromium; do not rely on routing to reject the initial navigation.
            if (!$requestGuard->allows($url)) {
                if ($allowedHosts !== [] && !HostPolicy::allows($url, $allowedHosts)) {
                    throw new \HElturkey\WebTrawler\Exception\HostNotAllowedException('The browser navigation host is not in the configured allow-list.');
                }
                throw new UnsafeUrlException('The browser navigation was refused by the configured network policy.');
            }
        }

        if (!class_exists(Playwright::class)) {
            throw new RuntimeException('Playwright PHP is not installed. Run: composer require playwright-php/playwright');
        }

        $sessionState = BrowserSessionState::fromOptions($options);
        $startedAt = hrtime(true);
        $launchOptions = [
            'headless' => (bool) ($options['headless'] ?? true),
        ];
        if (isset($options['args']) && !is_array($options['args'])) {
            throw new InvalidArgumentException('The browser args option must be a list of Chromium argument strings.');
        }
        $rawBrowserArgs = $options['args'] ?? [];
        if (!is_array($rawBrowserArgs) || !array_is_list($rawBrowserArgs) || array_filter($rawBrowserArgs, 'is_string') !== $rawBrowserArgs) {
            throw new InvalidArgumentException('The browser args option must be a list of strings.');
        }
        /** @var list<string> $browserArgs */
        $browserArgs = $rawBrowserArgs;
        foreach ($browserArgs as $argument) {
            $normalizedArgument = strtolower($argument);
            if (($options['enforce_proxy'] ?? false) === true && preg_match('/^--(?:proxy-|no-proxy-server)/', $normalizedArgument) === 1) {
                throw new InvalidArgumentException('Chromium proxy command-line arguments cannot override the enforced scraper proxy route.');
            }
            if ($requestGuard !== null && str_starts_with($normalizedArgument, '--host-resolver-rules')) {
                throw new InvalidArgumentException('Custom Chromium host-resolver rules are incompatible with the browser network security policy.');
            }
        }
        if ($browserArgs !== []) {
            $launchOptions['args'] = $browserArgs;
        }
        if (isset($options['slow_mo']) && is_numeric($options['slow_mo'])) {
            $launchOptions['slowMo'] = max(0, (int) $options['slow_mo']);
        }

        if (isset($options['context']) && !is_array($options['context'])) {
            throw new InvalidArgumentException('The browser context option must be an array.');
        }
        $contextOptions = is_array($options['context'] ?? null) ? $options['context'] : [];
        if (isset($options['verify_tls']) && !is_bool($options['verify_tls'])) {
            throw new InvalidArgumentException('The browser verify_tls option must be a boolean.');
        }
        if (array_key_exists('verify_tls', $options)) {
            $contextOptions['ignoreHTTPSErrors'] = !$options['verify_tls'];
        }
        if ($requestGuard !== null) {
            // Service workers can serve requests without going through context routing.
            $contextOptions['serviceWorkers'] = 'block';
        }
        // Playwright::chromium() ignores a top-level proxy option; it must go through the context.
        // A context-level proxy must not silently replace a scraper-enforced route.
        $configuredProxy = isset($options['proxy']) ? self::playwrightProxy($options['proxy'], $url) : null;
        if ($configuredProxy !== null) {
            if (isset($contextOptions['proxy']) && $contextOptions['proxy'] !== $configuredProxy) {
                throw new InvalidArgumentException('The browser context proxy cannot replace the configured scraper proxy route.');
            }
            $contextOptions['proxy'] = $configuredProxy;
        }
        $launchOptions['context'] = $contextOptions;
        $context = Playwright::chromium($launchOptions);
        $page = null;

        try {
            if (($statePath = $sessionState?->existingLoadPath()) !== null) {
                $context->loadStorageState($statePath);
            }

            if (isset($options['headers']) && is_array($options['headers'])) {
                $headers = [];
                foreach ($options['headers'] as $name => $value) {
                    if (is_string($name) && (is_string($value) || is_numeric($value))) {
                        $headers[$name] = (string) $value;
                    }
                }
                if ($headers !== []) {
                    $context->setExtraHTTPHeaders($headers);
                }
            }

            if ($requestGuard !== null) {
                // Original requests are filtered here. Redirect hops are NOT routed by Chromium; they are verified after navigation.
                $context->route('**/*', static function ($route) use ($requestGuard): void {
                    $routedUrl = $route->request()->url();
                    $requestGuard->markRouted($routedUrl);
                    if ($requestGuard->allows($routedUrl)) {
                        $route->continue();
                    } else {
                        $route->abort('blockedbyclient');
                    }
                });
            }

            $page = $context->newPage();

            /** @var list<array<string, mixed>> $network */
            $network = [];
            /** @var list<string> $rawUrls */
            $rawUrls = [];
            /** @var list<array{index: int, response: ResponseInterface, content_type: string, content_length: ?string}> $pendingBodies */
            $pendingBodies = [];

            $maximumRequests = max(1, min(10_000, (int) ($options['max_network_requests'] ?? 500)));
            $captureRequestBodies = (bool) ($options['capture_request_bodies'] ?? false);
            $maximumRequestBodyBytes = max(0, min(1_048_576, (int) ($options['max_request_body_bytes'] ?? 262_144)));
            $maximumTotalRequestBodyBytes = max(0, min(10_485_760, (int) ($options['max_total_request_body_bytes'] ?? 1_048_576)));
            $totalRequestBodyBytes = 0;

            $page->events()->onRequest(static function (RequestInterface $request) use (
                $requestGuard,
                &$network,
                &$rawUrls,
                $maximumRequests,
                $captureRequestBodies,
                $maximumRequestBodyBytes,
                $maximumTotalRequestBodyBytes,
                &$totalRequestBodyBytes,
            ): void {
                // Redirect hops and anything that bypassed routing show up here; the guard checks them afterwards.
                $requestGuard?->observe($request->url());

                if (count($network) >= $maximumRequests) {
                    return;
                }

                $record = [
                    'url' => NetworkMetadata::sanitizeUrl($request->url()),
                    'method' => $request->method(),
                    'resource_type' => $request->resourceType(),
                    'request_headers' => NetworkMetadata::sanitizeHeaders($request->headers()),
                    'status' => null,
                ];

                if ($captureRequestBodies && ($requestBody = $request->postData()) !== null) {
                    $remainingRequestBytes = max(0, $maximumTotalRequestBodyBytes - $totalRequestBodyBytes);
                    $requestBodyLimit = min($maximumRequestBodyBytes, $remainingRequestBytes);
                    $encodedBody = NetworkMetadata::encodeBody($requestBody, $requestBodyLimit);
                    $record['request_body'] = $encodedBody['body'];
                    $record['request_body_encoding'] = $encodedBody['encoding'];
                    $record['request_body_truncated'] = $encodedBody['truncated'];
                    $totalRequestBodyBytes += strlen($record['request_body']);
                }

                $network[] = $record;
                $rawUrls[] = $request->url();
            });

            $page->events()->onResponse(static function (ResponseInterface $response) use (
                &$network,
                &$rawUrls,
                &$pendingBodies,
                $maximumRequests,
            ): void {
                // Some Playwright bridge versions omit the nested request object
                // from response events. Response::request()->url() then throws.
                // The response URL is always available, so use it as the stable
                // correlation key and enrich metadata from the nested request only
                // when that data is valid.
                $rawUrl = $response->url();
                $method = null;
                $resourceType = null;
                $requestHeaders = [];

                try {
                    $request = $response->request();
                    $requestUrl = $request->url();
                    if ($requestUrl === $rawUrl) {
                        $method = $request->method();
                        $resourceType = $request->resourceType();
                        $requestHeaders = NetworkMetadata::sanitizeHeaders($request->headers());
                    }
                } catch (Throwable) {
                    // Fall back to URL correlation; do not let incomplete bridge
                    // request metadata abort an otherwise successful page render.
                }

                $recordIndex = NetworkRequestMatcher::findPendingIndex(
                    $rawUrl,
                    $method,
                    $resourceType,
                    $network,
                    $rawUrls,
                );

                if ($recordIndex === null) {
                    if (count($network) >= $maximumRequests) {
                        return;
                    }

                    $recordIndex = count($network);
                    $network[] = [
                        'url' => NetworkMetadata::sanitizeUrl($rawUrl),
                        'method' => $method ?? 'UNKNOWN',
                        'resource_type' => $resourceType ?? 'other',
                        'request_headers' => $requestHeaders,
                        'status' => null,
                    ];
                    $rawUrls[] = $rawUrl;
                }

                $network[$recordIndex]['status'] = $response->status();
                $network[$recordIndex]['response_headers'] = NetworkMetadata::sanitizeHeaders($response->headers());

                $matchedResourceType = $network[$recordIndex]['resource_type'] ?? null;
                if ($matchedResourceType === 'xhr' || $matchedResourceType === 'fetch') {
                    $pendingBodies[] = [
                        'index' => $recordIndex,
                        'response' => $response,
                        'content_type' => strtolower($response->headerValue('content-type') ?? ''),
                        'content_length' => $response->headerValue('content-length'),
                    ];
                }
            });

            $page->events()->onRequestFailed(static function (RequestInterface $request) use (
                &$network,
                &$rawUrls,
                $maximumRequests,
            ): void {
                try {
                    $rawUrl = $request->url();
                    $method = $request->method();
                    $resourceType = $request->resourceType();
                } catch (Throwable) {
                    return;
                }

                $recordIndex = NetworkRequestMatcher::findPendingIndex(
                    $rawUrl,
                    $method,
                    $resourceType,
                    $network,
                    $rawUrls,
                );
                if ($recordIndex === null) {
                    if (count($network) >= $maximumRequests) {
                        return;
                    }

                    $recordIndex = count($network);
                    $network[] = [
                        'url' => NetworkMetadata::sanitizeUrl($rawUrl),
                        'method' => $method,
                        'resource_type' => $resourceType,
                        'request_headers' => NetworkMetadata::sanitizeHeaders($request->headers()),
                        'status' => null,
                    ];
                    $rawUrls[] = $rawUrl;
                }

                $failure = $request->failure();
                $network[$recordIndex]['failure'] = is_array($failure) && is_string($failure['errorText'] ?? null)
                    ? $failure['errorText']
                    : 'Request failed';
            });

            $gotoOptions = [];
            if (isset($options['timeout']) && is_numeric($options['timeout'])) {
                $gotoOptions['timeout'] = max(0, (int) $options['timeout']);
            }

            $waitUntil = $options['wait_until'] ?? 'load';
            if (is_string($waitUntil) && in_array($waitUntil, ['commit', 'domcontentloaded', 'load', 'networkidle'], true)) {
                $gotoOptions['waitUntil'] = $waitUntil;
            }

            try {
                $navigationResponse = $page->goto($url, $gotoOptions);
            } catch (Throwable $navigationError) {
                if ($requestGuard !== null && $requestGuard->blockedUrls() !== []) {
                    throw new UnsafeUrlException(
                        'Navigation blocked by the browser network policy: ' . implode(', ', array_slice($requestGuard->blockedUrls(), 0, 3)),
                        0,
                        $navigationError,
                    );
                }
                throw $navigationError;
            }

            if (isset($options['wait_for_selector']) && is_string($options['wait_for_selector'])) {
                $selectorTimeout = is_numeric($options['selector_timeout'] ?? null)
                    ? max(1, (int) $options['selector_timeout'])
                    : 10_000;
                $page->waitForSelector($options['wait_for_selector'], ['timeout' => $selectorTimeout]);
            }

            if (isset($options['delay_ms']) && is_numeric($options['delay_ms'])) {
                $delay = max(0, min(30_000, (int) $options['delay_ms']));
                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }

            if ($requestGuard !== null) {
                $chain = [];
                $hop = $navigationResponse?->request();
                for ($depth = 0; $hop !== null && $depth < 50; $depth++) {
                    $chain[] = $hop->url();
                    $hop = $hop->redirectedFrom();
                }
                $connectedAddress = null;
                try {
                    $address = $navigationResponse?->serverAddr();
                    $connectedAddress = is_array($address) ? ($address['ipAddress'] ?? null) : null;
                } catch (Throwable) {
                    // Not every browser build reports the address; the URL checks above still apply.
                }

                $problems = $requestGuard->verifyNavigation($chain, is_string($connectedAddress) ? $connectedAddress : null, $page->url());
                if ($problems !== []) {
                    // Nothing from the page is returned: no HTML, no captured bodies, no saved session.
                    throw new UnsafeUrlException('Browser render rejected by the configured network policy: ' . implode('; ', array_slice($problems, 0, 3)));
                }
            }

            if ((bool) ($options['capture_response_bodies'] ?? false)) {
                $patterns = is_array($options['network_capture_patterns'] ?? null)
                    ? $options['network_capture_patterns']
                    : [];
                $limit = max(0, min(10_485_760, (int) ($options['max_response_body_bytes'] ?? 1_048_576)));
                $totalLimit = max(0, min(52_428_800, (int) ($options['max_total_response_body_bytes'] ?? 5_242_880)));
                $totalCapturedBytes = 0;

                foreach ($pendingBodies as $pending) {
                    $recordIndex = $pending['index'];
                    $rawUrl = $rawUrls[$recordIndex] ?? (string) ($network[$recordIndex]['url'] ?? '');
                    $isTextResponse = preg_match('/(?:json|xml|text|javascript|graphql)/', $pending['content_type']) === 1;
                    if (!$isTextResponse || ($patterns !== [] && !self::matchesAnyPattern($rawUrl, $patterns))) {
                        continue;
                    }

                    $remainingBytes = max(0, $totalLimit - $totalCapturedBytes);
                    $bodyLimit = min($limit, $remainingBytes);
                    if ($bodyLimit === 0) {
                        $network[$recordIndex]['body_truncated'] = true;
                        continue;
                    }

                    $contentLength = $pending['content_length'];
                    if (!is_string($contentLength) || !ctype_digit($contentLength)) {
                        // Response::body() materializes the complete payload in PHP memory. Without a valid
                        // declared size, skip capture rather than risk buffering an unbounded body just to
                        // truncate it afterwards. A dishonest Content-Length is still why this is not a hard
                        // browser memory/network limit; see docs/browser-engine.md.
                        $network[$recordIndex]['body_truncated'] = true;
                        $network[$recordIndex]['body_capture_skipped_reason'] = 'missing_content_length';
                        continue;
                    }
                    if ((int) $contentLength > $bodyLimit) {
                        $network[$recordIndex]['body_truncated'] = true;
                        $network[$recordIndex]['body_capture_skipped_reason'] = 'content_length_over_limit';
                        continue;
                    }

                    try {
                        $body = $pending['response']->body();
                        $truncated = strlen($body) > $bodyLimit;
                        $network[$recordIndex]['response_body'] = $truncated
                            ? NetworkMetadata::truncate($body, $bodyLimit)
                            : $body;
                        $network[$recordIndex]['body_truncated'] = $truncated;
                        $totalCapturedBytes += strlen($network[$recordIndex]['response_body']);
                    } catch (Throwable) {
                        $network[$recordIndex]['body_capture_failed'] = true;
                    }
                }
            }

            $html = $page->content() ?? '';
            $responseHeaders = [];
            if ($navigationResponse !== null) {
                foreach ($navigationResponse->headers() as $name => $value) {
                    $responseHeaders[$name] = [NetworkMetadata::sanitizeHeaders([$name => $value])[$name] ?? ''];
                }
            }

            // Save cookies and localStorage before closing the browser context.
            // A storage_state path is both loaded (when present) and refreshed.
            $sessionState?->persist(static function (string $temporaryPath) use ($context): void {
                $context->saveStorageState($temporaryPath);
            });

            return new BrowserResult(
                html: $html,
                requestedUrl: $url,
                finalUrl: $page->url(),
                headers: $responseHeaders,
                networkRequests: $network,
                durationMs: (hrtime(true) - $startedAt) / 1_000_000,
                statusCode: $navigationResponse?->status(),
            );
        } finally {
            try {
                $page?->close();
            } catch (Throwable) {
                // Preserve the navigation/parsing exception if the page already closed.
            }
            try {
                $context->close();
            } catch (Throwable) {
                // Preserve the original failure.
            }
            try {
                $context->browser()?->close();
            } catch (Throwable) {
                // Preserve the original failure.
            }
        }
    }

    private static function assertHttpUrl(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('PlaywrightBrowserDriver only accepts valid HTTP and HTTPS URLs.');
        }
    }

    /** @return array<string, mixed>|null */
    private static function playwrightProxy(mixed $proxy, string $url): ?array
    {
        if (is_string($proxy) && $proxy !== '') {
            return ['server' => $proxy];
        }
        if (!is_array($proxy)) {
            throw new InvalidArgumentException('The browser proxy must be a URL or proxy configuration array.');
        }
        if (array_key_exists('server', $proxy)) {
            foreach ($proxy as $key => $value) {
                if (!in_array($key, ['server', 'bypass', 'username', 'password'], true)) {
                    throw new InvalidArgumentException(sprintf('Unsupported Playwright proxy option "%s".', (string) $key));
                }
                if (!is_string($value) || ($key === 'server' && $value === '')) {
                    throw new InvalidArgumentException(sprintf('The Playwright proxy "%s" option must be a string%s.', (string) $key, $key === 'server' ? ' URL' : ''));
                }
            }

            return $proxy;
        }

        $rawNoProxy = $proxy['no'] ?? [];
        if (!is_array($rawNoProxy) || !array_is_list($rawNoProxy)) {
            throw new InvalidArgumentException('The browser proxy "no" option must be a list of host patterns.');
        }

        $noProxy = [];
        foreach ($rawNoProxy as $hostPattern) {
            if (!is_string($hostPattern) || $hostPattern === '') {
                throw new InvalidArgumentException('The browser proxy "no" option must contain non-empty host patterns.');
            }
            $noProxy[] = $hostPattern;
        }

        foreach ($proxy as $key => $value) {
            if (!in_array($key, ['http', 'https', 'no'], true)) {
                throw new InvalidArgumentException(sprintf('Unsupported browser proxy option "%s".', (string) $key));
            }
            if ($key === 'no') {
                continue;
            }
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException(sprintf('The browser %s proxy must be a non-empty URL.', $key));
            }
        }
        if (isset($proxy['http'], $proxy['https']) && $proxy['http'] !== $proxy['https']) {
            throw new InvalidArgumentException('Playwright supports one proxy server per browser context; different HTTP and HTTPS proxy URLs cannot be represented safely.');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $server = $proxy[$scheme] ?? null;
        if ($server === null) {
            if (isset($proxy['http']) || isset($proxy['https'])) {
                throw new InvalidArgumentException(sprintf('No %s proxy was configured for the browser navigation; refusing to fall back to a direct connection.', $scheme));
            }
            return null;
        }

        $result = ['server' => $server];
        if ($noProxy !== []) {
            $result['bypass'] = implode(',', $noProxy);
        }

        return $result;
    }

    /** @param array<mixed> $patterns */
    private static function matchesAnyPattern(string $url, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && $pattern !== '' && fnmatch($pattern, $url)) {
                return true;
            }
        }

        return false;
    }
}
