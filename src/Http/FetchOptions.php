<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

use HElturkey\WebTrawler\Exception\HostNotAllowedException;
use RuntimeException;

final readonly class FetchOptions
{
    /** Default response-size ceiling (20 MiB). Pass null to disable. */
    public const DEFAULT_MAX_BODY_BYTES = 20_971_520;

    /**
     * @param array<string, string|string[]> $headers
     * @param string|array<string, mixed>|null $proxy
     * @param array<string, mixed> $extra Guzzle options for advanced use cases. Headers given here are
     *                                    merged beneath $headers; every other explicit option wins over $extra.
     * @param int|null $maxBodyBytes Abort and throw ResponseTooLargeException when the body exceeds this size.
     * @param bool $blockPrivateNetworks Refuse loopback/private/link-local targets (see UrlGuard for its limits).
     * @param string|null $encoding Charset of the response body, e.g. 'utf-8' or 'windows-1256'. An explicit
     *                              value always wins over the HTTP header and <meta>. null (default) detects
     *                              it like a browser: BOM, Content-Type, <meta>, then UTF-8 if valid.
     * @param list<string> $allowedHosts When not empty, only these hosts (and their subdomains) may be requested,
     *                                   and every redirect hop is checked against the list before it is followed.
     */
    public function __construct(
        public array $headers = [],
        public float $timeout = 30.0,
        public float $connectTimeout = 10.0,
        public string|array|null $proxy = null,
        public bool $verifyTls = true,
        public bool $allowRedirects = true,
        public int $maxRedirects = 10,
        public array $extra = [],
        public ?int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
        public bool $blockPrivateNetworks = false,
        public ?string $encoding = null,
        public array $allowedHosts = [],
    ) {
        if (!is_finite($timeout) || !is_finite($connectTimeout) || $timeout < 0 || $connectTimeout < 0) {
            throw new \InvalidArgumentException('Timeout values must be finite and cannot be negative.');
        }

        if ($maxRedirects < 0) {
            throw new \InvalidArgumentException('Maximum redirects cannot be negative.');
        }

        if ($encoding !== null && trim($encoding) === '') {
            throw new \InvalidArgumentException('The encoding must be a charset name or null.');
        }

        if ($maxBodyBytes !== null && $maxBodyBytes < 1) {
            throw new \InvalidArgumentException('The maximum body size must be positive or null.');
        }

        if (!array_is_list($allowedHosts)) {
            throw new \InvalidArgumentException('Allowed hosts must be a list of non-empty host names.');
        }
        foreach ($allowedHosts as $host) {
            if (!is_string($host) || trim($host, ". \t") === '') {
                throw new \InvalidArgumentException('Allowed hosts must be a list of non-empty host names.');
            }
        }
    }

    /** Return a copy with one additional transport ("extra") option set, e.g. form_params. */
    public function withExtraOption(string $name, mixed $value): self
    {
        return $this->rebuild([...$this->extra, $name => $value], $this->allowedHosts, $this->maxBodyBytes, $this->blockPrivateNetworks);
    }

    /**
     * Rebuild this immutable value while changing only the supplied policy-related fields.
     *
     * @param array<string, mixed> $extra
     * @param list<string> $allowedHosts
     */
    private function rebuild(array $extra, array $allowedHosts, ?int $maxBodyBytes, bool $blockPrivateNetworks): self
    {
        return new self(
            headers: $this->headers,
            timeout: $this->timeout,
            connectTimeout: $this->connectTimeout,
            proxy: $this->proxy,
            verifyTls: $this->verifyTls,
            allowRedirects: $this->allowRedirects,
            maxRedirects: $this->maxRedirects,
            extra: $extra,
            maxBodyBytes: $maxBodyBytes,
            blockPrivateNetworks: $blockPrivateNetworks,
            encoding: $this->encoding,
            allowedHosts: $allowedHosts,
        );
    }

    /** @param list<string> $allowedHosts */
    public function withAllowedHosts(array $allowedHosts): self
    {
        return $this->rebuild($this->extra, $allowedHosts, $this->maxBodyBytes, $this->blockPrivateNetworks);
    }

    /**
     * Combine these (per-request) options with an application security policy. Limits can only become
     * stricter: timeouts and body size take the tighter non-zero/specified value, TLS verification cannot
     * be disabled, redirects cannot be enabled when the policy disables them, host lists are intersected,
     * private networks remain blocked, and an enforced proxy cannot be replaced.
     *
     * @throws HostNotAllowedException when both non-empty allow-lists have no permitted host in common.
     */
    public function tightenedBy(self $policy): self
    {
        if ($policy->proxy !== null && $this->proxy !== null && $this->proxy !== $policy->proxy) {
            throw new \InvalidArgumentException('The request cannot replace the proxy route enforced by the scraper security policy.');
        }

        $maxBytes = match (true) {
            $policy->maxBodyBytes === null => $this->maxBodyBytes,
            $this->maxBodyBytes === null => $policy->maxBodyBytes,
            default => min($this->maxBodyBytes, $policy->maxBodyBytes),
        };

        if ($policy->allowedHosts === []) {
            $hosts = $this->allowedHosts;
        } elseif ($this->allowedHosts === []) {
            $hosts = $policy->allowedHosts;
        } else {
            $hosts = self::intersectHostAllowLists($this->allowedHosts, $policy->allowedHosts);
        }

        // In this API, 0 means unlimited, so a finite policy timeout wins over an unlimited request.
        $timeout = self::tighterTimeout($this->timeout, $policy->timeout);
        $connectTimeout = self::tighterTimeout($this->connectTimeout, $policy->connectTimeout);

        return new self(
            headers: array_replace($policy->headers, $this->headers),
            timeout: $timeout,
            connectTimeout: $connectTimeout,
            proxy: $this->proxy ?? $policy->proxy,
            verifyTls: $this->verifyTls || $policy->verifyTls,
            allowRedirects: $this->allowRedirects && $policy->allowRedirects,
            maxRedirects: min($this->maxRedirects, $policy->maxRedirects),
            extra: $this->extra,
            maxBodyBytes: $maxBytes,
            blockPrivateNetworks: $this->blockPrivateNetworks || $policy->blockPrivateNetworks,
            encoding: $this->encoding ?? $policy->encoding,
            allowedHosts: $hosts,
        );
    }

    private static function tighterTimeout(float $request, float $policy): float
    {
        if ($policy === 0.0) {
            return $request;
        }
        if ($request === 0.0) {
            return $policy;
        }

        return min($request, $policy);
    }

    /**
     * Serialize options for resumable crawl state. The proxy is deliberately omitted because it may contain
     * credentials. Transport extras that affect a request must be JSON-safe and supported by the state format;
     * silently dropping one would resume a different request from the one originally queued.
     *
     * @return array<string, mixed>
     */
    public function toState(): array
    {
        if ($this->proxy !== null) {
            throw new RuntimeException('Cannot checkpoint crawl: an explicit request proxy is not persisted because it may contain credentials. Configure a ProxyTransport pool or resolve a proxy reference when the crawl resumes.');
        }

        if (!self::isJsonSafeValue($this->headers) || !self::isJsonSafeValue($this->allowedHosts) || ($this->encoding !== null && !self::isJsonSafeValue($this->encoding))) {
            throw new RuntimeException('Cannot checkpoint crawl: FetchOptions headers, allow-list or encoding contains data that is not valid JSON UTF-8.');
        }

        $extra = [];
        $supportedExtraKeys = ['form_params', 'json', 'query', 'body', 'headers'];
        foreach ($this->extra as $key => $value) {
            if (!is_string($key) || !in_array($key, $supportedExtraKeys, true)) {
                throw new RuntimeException(sprintf('Cannot checkpoint crawl: FetchOptions extra option "%s" is not supported by resumable state.', (string) $key));
            }
            if (!self::isJsonSafeValue($value)) {
                throw new RuntimeException(sprintf('Cannot checkpoint crawl: FetchOptions extra option "%s" is not JSON-safe. Use scalar/array UTF-8 data or remove the resumable state store.', $key));
            }
            $extra[$key] = $value;
        }

        return [
            'headers' => $this->headers,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'verify_tls' => $this->verifyTls,
            'allow_redirects' => $this->allowRedirects,
            'max_redirects' => $this->maxRedirects,
            'max_body_bytes' => $this->maxBodyBytes,
            'block_private_networks' => $this->blockPrivateNetworks,
            'encoding' => $this->encoding,
            'allowed_hosts' => $this->allowedHosts,
            'extra' => $extra,
        ];
    }

    /** @param array<string, mixed> $state */
    public static function fromState(array $state): self
    {
        $headers = [];
        foreach (is_array($state['headers'] ?? null) ? $state['headers'] : [] as $name => $value) {
            if (is_string($name) && (is_string($value) || is_array($value))) {
                $headers[$name] = $value;
            }
        }
        $hosts = array_values(array_filter(is_array($state['allowed_hosts'] ?? null) ? $state['allowed_hosts'] : [], static fn (mixed $host): bool => is_string($host) && trim($host, ". \t") !== ''));

        return new self(
            headers: $headers,
            timeout: (float) ($state['timeout'] ?? 30.0),
            connectTimeout: (float) ($state['connect_timeout'] ?? 10.0),
            verifyTls: (bool) ($state['verify_tls'] ?? true),
            allowRedirects: (bool) ($state['allow_redirects'] ?? true),
            maxRedirects: (int) ($state['max_redirects'] ?? 10),
            extra: is_array($state['extra'] ?? null) ? $state['extra'] : [],
            maxBodyBytes: isset($state['max_body_bytes']) ? (int) $state['max_body_bytes'] : null,
            blockPrivateNetworks: (bool) ($state['block_private_networks'] ?? false),
            encoding: isset($state['encoding']) && is_string($state['encoding']) ? $state['encoding'] : null,
            allowedHosts: $hosts,
        );
    }

    /**
     * Whether a value can be faithfully represented by the JSON crawl-state format.
     * Objects, resources, non-finite floats and invalid UTF-8 strings are rejected rather than changed or lost.
     */
    public static function isJsonSafeValue(mixed $value): bool
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
        }
        if (is_string($value)) {
            return preg_match('//u', $value) === 1;
        }
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('//u', $key) !== 1) {
                return false;
            }
            if (!self::isJsonSafeValue($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Intersect two host allow-lists. Each entry authorizes that exact host and its subdomains,
     * so the narrower descendant is retained when one entry is a subdomain of the other.
     *
     * @param list<string> $requestHosts
     * @param list<string> $policyHosts
     * @return list<string>
     */
    private static function intersectHostAllowLists(array $requestHosts, array $policyHosts): array
    {
        $intersection = [];
        foreach ($requestHosts as $requestHost) {
            $requestHost = strtolower(trim($requestHost, ". \t"));
            foreach ($policyHosts as $policyHost) {
                $policyHost = strtolower(trim($policyHost, ". \t"));
                if ($requestHost === $policyHost || str_ends_with($requestHost, '.' . $policyHost)) {
                    $intersection[] = $requestHost;
                } elseif (str_ends_with($policyHost, '.' . $requestHost)) {
                    $intersection[] = $policyHost;
                }
            }
        }

        $intersection = array_values(array_unique($intersection));
        if ($intersection === []) {
            throw new HostNotAllowedException('The request host allow-list does not intersect the enforced host policy.');
        }

        return $intersection;
    }

    /** @return array<string, mixed> */
    public function toTransportOptions(): array
    {
        $extraHeaders = $this->extra['headers'] ?? [];
        $options = array_replace($this->extra, [
            'headers' => array_replace(is_array($extraHeaders) ? $extraHeaders : [], $this->headers),
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'verify' => $this->verifyTls,
            'allow_redirects' => $this->allowRedirects
                ? ['max' => $this->maxRedirects, 'strict' => false, 'referer' => true, 'protocols' => ['http', 'https']]
                : false,
            'http_errors' => false,
            'max_body_bytes' => $this->maxBodyBytes,
            'block_private_networks' => $this->blockPrivateNetworks,
            'allowed_hosts' => $this->allowedHosts,
        ]);

        if ($this->proxy !== null) {
            $options['proxy'] = $this->proxy;
        }

        return $options;
    }
}
