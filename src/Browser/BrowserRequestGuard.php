<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Browser;

use HElturkey\WebTrawler\Http\HostPolicy;
use HElturkey\WebTrawler\Http\UrlGuard;
use HElturkey\WebTrawler\Support\UrlSanitizer;

/**
 * Best-effort browser request policy. It applies host allow-lists and optional public-address checks to
 * requests visible to Playwright. It is not an egress security boundary: redirects may reach their target
 * before post-navigation verification, and DNS can rebind between the check and Chromium's connection.
 */
final class BrowserRequestGuard
{
    private const MAX_OBSERVED = 10_000;

    /** @var array<string, bool> */
    private array $hostDecisions = [];

    /** @var list<string> */
    private array $blocked = [];

    /** @var array<string, true> */
    private array $routed = [];

    /** @var array<string, true> */
    private array $observed = [];

    private bool $observationOverflow = false;

    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly bool $blockPrivateNetworks = true,
        private readonly array $allowedHosts = [],
    ) {
        if (!array_is_list($allowedHosts)) {
            throw new \InvalidArgumentException('Browser allowed hosts must be a list of host names.');
        }
        foreach ($allowedHosts as $host) {
            if (!is_string($host) || trim($host, ". \t") === '') {
                throw new \InvalidArgumentException('Browser allowed hosts must be a list of non-empty host names.');
            }
        }
    }

    public function allows(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        // Inline resources never touch the network.
        if ($scheme === 'data' || $scheme === 'blob' || $url === 'about:blank') {
            return true;
        }

        if (!in_array($scheme, ['http', 'https', 'ws', 'wss'], true)) {
            return $this->block($url);
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return $this->block($url);
        }

        if (!HostPolicy::allows($url, $this->allowedHosts)) {
            return $this->block($url);
        }

        if (!$this->blockPrivateNetworks) {
            return true;
        }

        $decisionKey = $scheme . '://' . $host;
        if (!isset($this->hostDecisions[$decisionKey])) {
            try {
                // ws(s) host checks use the same address policy; the scheme is irrelevant to the address.
                UrlGuard::assertPublic('http://' . $host . '/');
                $this->hostDecisions[$decisionKey] = true;
            } catch (\Throwable) {
                $this->hostDecisions[$decisionKey] = false;
            }
        }

        return $this->hostDecisions[$decisionKey] ? true : $this->block($url);
    }

    /** Record that the route handler saw (and decided about) this request URL. */
    public function markRouted(string $url): void
    {
        $this->routed[$url] = true;
    }

    /** Record any request Chromium made, whether or not the route handler ever saw it (bounded). */
    public function observe(string $url): void
    {
        if (isset($this->observed[$url])) {
            return;
        }
        if (count($this->observed) >= self::MAX_OBSERVED) {
            $this->observationOverflow = true;

            return;
        }
        $this->observed[$url] = true;
    }

    /**
     * Post-navigation verification. Returns human-readable problems (empty = nothing disallowed was observed).
     *
     * @param list<string> $redirectChain URLs of the main-frame request and every redirect hop before it
     * @param string|null $connectedAddress IP address Chromium reports for the main response
     * @return list<string>
     */
    public function verifyNavigation(array $redirectChain, ?string $connectedAddress, ?string $finalUrl = null): array
    {
        $problems = [];

        // Redirects or traffic bypassing the route handler are checked after Chromium has attempted them.
        foreach (array_keys($this->observed) as $url) {
            if (!isset($this->routed[$url]) && !$this->allows((string) $url)) {
                $problems[] = 'unfiltered request to ' . UrlSanitizer::sanitize((string) $url);
            }
        }
        foreach ([...$redirectChain, ...($finalUrl !== null ? [$finalUrl] : [])] as $url) {
            if (!$this->allows($url)) {
                $problems[] = 'navigation reached a disallowed URL ' . UrlSanitizer::sanitize($url);
            }
        }
        if ($this->blockPrivateNetworks && $connectedAddress !== null && !UrlGuard::isPublicIp($connectedAddress)) {
            $problems[] = 'the browser connected to a non-public address (' . $connectedAddress . ')';
        }
        if ($this->observationOverflow) {
            $problems[] = 'too many requests to verify';
        }

        return array_values(array_unique($problems));
    }

    /** @return list<string> URLs refused during this render, with secrets removed. */
    public function blockedUrls(): array
    {
        return $this->blocked;
    }

    private function block(string $url): bool
    {
        if (count($this->blocked) < 100) {
            $this->blocked[] = UrlSanitizer::sanitize($url);
        }

        return false;
    }
}
