<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use GuzzleHttp\Promise\PromiseInterface;
use HElturkey\WebTrawler\Contracts\AsyncHttpTransport;
use HElturkey\WebTrawler\Contracts\HttpTransport;
use HElturkey\WebTrawler\Contracts\SnapshotStore;
use HElturkey\WebTrawler\Exception\ResponseTooLargeException;
use HElturkey\WebTrawler\Parsing\Page;

final readonly class Fetcher
{
    public function __construct(
        private HttpTransport $transport,
        private SnapshotStore $snapshotStore,
        private AdaptiveMatcher $adaptiveMatcher,
        private ?FetchOptions $defaultOptions = null,
        private bool $enforceDefaults = false,
        private ?RetryPolicy $defaultRetryPolicy = null,
    ) {
    }

    /** Options used when a call does not pass its own (app-wide timeouts, size limits, SSRF policy ...). */
    public function defaultOptions(): FetchOptions
    {
        return $this->defaultOptions ?? new FetchOptions();
    }

    /**
     * With $enforceDefaults, the default options act as a security policy: whatever options a call (or a
     * spider request) supplies, private-network blocking, the size limit and the host allow-list can only
     * become stricter than the defaults, never looser.
     */
    private function resolve(?FetchOptions $options): FetchOptions
    {
        if ($options === null) {
            return $this->defaultOptions();
        }

        return $this->enforceDefaults && $this->defaultOptions !== null ? $options->tightenedBy($this->defaultOptions) : $options;
    }

    public function fetch(string $url, ?FetchOptions $options = null, string $method = 'GET', bool $applyDefaultRetries = true): Page
    {
        $options = $this->resolve($options);
        $url = $this->prepareUrl($url, $options);

        $transport = $applyDefaultRetries && $this->defaultRetryPolicy !== null
            ? new RetryingTransport($this->transport, $this->defaultRetryPolicy)
            : $this->transport;
        $response = $transport->send($method, $url, $options->toTransportOptions());

        return $this->toPage($response, $url, $options);
    }

    /**
     * Resolve browser security settings with the same scraper-level defaults used by HTTP requests.
     * Browser size limits are checked against the returned document, but do not bound Chromium's internal
     * network or memory use; hostile browser targets still require network-level egress isolation.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function browserOptions(array $options): array
    {
        $defaults = $this->defaultOptions();
        $timeoutMs = $options['timeout'] ?? ($defaults->timeout * 1000);
        if (!is_int($timeoutMs) && !is_float($timeoutMs)) {
            throw new \InvalidArgumentException('The browser timeout must be a finite non-negative number of milliseconds.');
        }
        if (!is_finite((float) $timeoutMs) || $timeoutMs < 0) {
            throw new \InvalidArgumentException('The browser timeout must be a finite non-negative number of milliseconds.');
        }

        $blockPrivate = $options['block_private_networks'] ?? $defaults->blockPrivateNetworks;
        if (!is_bool($blockPrivate)) {
            throw new \InvalidArgumentException('The browser block_private_networks option must be a boolean.');
        }
        $verifyTls = $options['verify_tls'] ?? $defaults->verifyTls;
        if (!is_bool($verifyTls)) {
            throw new \InvalidArgumentException('The browser verify_tls option must be a boolean.');
        }

        $proxy = array_key_exists('proxy', $options) ? $options['proxy'] : $defaults->proxy;
        if ($proxy !== null && !is_string($proxy) && !is_array($proxy)) {
            throw new \InvalidArgumentException('The browser proxy option must be a URL string, a proxy configuration array, or null.');
        }

        $allowedHosts = $options['allowed_hosts'] ?? $defaults->allowedHosts;
        if (!is_array($allowedHosts) || !array_is_list($allowedHosts) || array_filter($allowedHosts, static fn (mixed $host): bool => is_string($host) && trim($host, ". \t") !== '') !== $allowedHosts) {
            throw new \InvalidArgumentException('The browser allowed_hosts option must be a list of non-empty host names.');
        }

        $maxBodyBytes = array_key_exists('max_body_bytes', $options) ? $options['max_body_bytes'] : $defaults->maxBodyBytes;
        if ($maxBodyBytes !== null && (!is_int($maxBodyBytes) || $maxBodyBytes < 1)) {
            throw new \InvalidArgumentException('The browser max_body_bytes option must be a positive integer or null.');
        }

        $allowRedirects = $options['allow_redirects'] ?? $defaults->allowRedirects;
        if (!is_bool($allowRedirects)) {
            throw new \InvalidArgumentException('Browser allow_redirects must be a boolean; Playwright does not support Guzzle redirect options.');
        }
        if (!$allowRedirects) {
            throw new \LogicException('Playwright follows browser redirects internally; a no-redirect policy cannot be enforced safely by this browser driver.');
        }

        // Playwright timeouts use milliseconds; convert to FetchOptions seconds before policy comparison.
        $requestOptions = new FetchOptions(
            timeout: (float) $timeoutMs / 1000,
            connectTimeout: $defaults->connectTimeout,
            proxy: $proxy,
            maxBodyBytes: $maxBodyBytes,
            verifyTls: $verifyTls,
            blockPrivateNetworks: $blockPrivate,
            allowedHosts: $allowedHosts,
        );

        if ($this->enforceDefaults && $this->defaultOptions !== null) {
            $requestOptions = $requestOptions->tightenedBy($this->defaultOptions);
        }

        return array_replace($options, [
            'timeout' => $requestOptions->timeout * 1000,
            'proxy' => $requestOptions->proxy,
            'max_body_bytes' => $requestOptions->maxBodyBytes,
            'block_private_networks' => $requestOptions->blockPrivateNetworks,
            'verify_tls' => $requestOptions->verifyTls,
            'allowed_hosts' => $requestOptions->allowedHosts,
            'enforce_proxy' => $this->enforceDefaults && $this->defaultOptions?->proxy !== null,
        ]);
    }

    public function defaultRetryPolicy(): ?RetryPolicy
    {
        return $this->defaultRetryPolicy;
    }

    public function supportsAsync(): bool
    {
        return $this->transport instanceof AsyncHttpTransport && $this->transport->supportsAsync();
    }

    /**
     * Start a request without waiting. URL problems throw immediately; transport problems reject the promise.
     *
     * @return PromiseInterface Resolves to a Page.
     */
    public function fetchAsync(string $url, ?FetchOptions $options = null, string $method = 'GET'): PromiseInterface
    {
        if (!$this->transport instanceof AsyncHttpTransport || !$this->transport->supportsAsync()) {
            throw new \LogicException('The configured transport does not support concurrent requests.');
        }
        $options = $this->resolve($options);
        $url = $this->prepareUrl($url, $options);

        return $this->transport->sendAsync($method, $url, $options->toTransportOptions())
            ->then(fn (FetchResponse $response): Page => $this->toPage($response, $url, $options));
    }

    private function prepareUrl(string $url, FetchOptions $options): string
    {
        $url = UrlNormalizer::normalize($url);
        HostPolicy::assert($url, $options->allowedHosts);
        if ($options->blockPrivateNetworks) {
            UrlGuard::assertPublic($url);
        }

        return $url;
    }

    private function toPage(FetchResponse $response, string $url, FetchOptions $options): Page
    {
        // Backstop for transports that cannot enforce the limit while streaming.
        if ($options->maxBodyBytes !== null && strlen($response->body) > $options->maxBodyBytes) {
            throw ResponseTooLargeException::forLimit($options->maxBodyBytes, $url);
        }
        if ($response->effectiveUrl !== $url) {
            // Redirect hops are checked in-flight by the shipped transports; this covers any other transport.
            HostPolicy::assert($response->effectiveUrl, $options->allowedHosts);
            if ($options->blockPrivateNetworks) {
                UrlGuard::assertPublic($response->effectiveUrl);
            }
        }

        return Page::fromResponse($response, $this->snapshotStore, $this->adaptiveMatcher, $options->encoding);
    }

    public function get(string $url, ?FetchOptions $options = null): Page
    {
        return $this->fetch($url, $options, 'GET');
    }

    /** @param array<string, mixed> $data */
    public function post(string $url, array $data = [], ?FetchOptions $options = null): Page
    {
        return $this->fetch($url, ($options ?? $this->defaultOptions())->withExtraOption('form_params', $data), 'POST');
    }
}
