<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Policy;

use Closure;
use GuzzleHttp\Psr7\Uri;
use HElturkey\WebTrawler\Exception\HostNotAllowedException;
use HElturkey\WebTrawler\Exception\UnsafeUrlException;
use HElturkey\WebTrawler\Response\FetchResponse;
use Throwable;

/**
 * Fetches, caches and applies robots.txt per origin (scheme + host + port).
 *
 * Availability follows RFC 9309: a 2xx response is parsed; a 4xx response means "no restrictions"; a server
 * error or an unreachable host means "assume the whole site is disallowed", cached only briefly so the crawl
 * recovers once the server does. Requests blocked by the scraper's own URL policy are not swallowed: the
 * policy exception propagates so the crawl reports the real reason.
 *
 * Fetching is synchronous and happens at most once per origin per TTL.
 */
final class RobotsPolicy
{
    /** @var array<string, array{0: RobotsTxt, 1: int}> origin => [rules, expiresAt] */
    private array $cache = [];

    /** @var Closure(string): FetchResponse */
    private Closure $fetch;

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param callable(string): FetchResponse $fetch Receives the robots.txt URL and returns its response.
     * @param (callable(): int)|null $clock Unix seconds; injectable for tests.
     */
    public function __construct(
        callable $fetch,
        private readonly string $userAgent,
        private readonly int $ttlSeconds = 86_400,
        private readonly int $failureTtlSeconds = 300,
        ?callable $clock = null,
        private readonly int $maxOrigins = 1_000,
    ) {
        $this->fetch = Closure::fromCallable($fetch);
        $this->clock = $clock !== null ? Closure::fromCallable($clock) : static fn (): int => time();
    }

    public function isAllowed(string $url): bool
    {
        return $this->rulesFor($url)->isUrlAllowed($this->userAgent, $url);
    }

    /** Crawl-delay for the URL's origin in milliseconds (0 when none is declared). */
    public function crawlDelayMs(string $url): int
    {
        $seconds = $this->rulesFor($url)->crawlDelaySeconds($this->userAgent);

        return $seconds === null ? 0 : (int) ceil($seconds * 1000);
    }

    /** @return list<string> Sitemap URLs the origin declares in its robots.txt. */
    public function sitemaps(string $url): array
    {
        return $this->rulesFor($url)->sitemaps();
    }

    private function rulesFor(string $url): RobotsTxt
    {
        $uri = new Uri(UrlNormalizer::normalize($url));
        $origin = $uri->getScheme() . '://' . $uri->getAuthority();
        $now = ($this->clock)();

        if (isset($this->cache[$origin]) && $this->cache[$origin][1] > $now) {
            return $this->cache[$origin][0];
        }

        [$rules, $ttl] = $this->load($origin . '/robots.txt');
        if (count($this->cache) >= $this->maxOrigins) {
            array_shift($this->cache);
        }
        $this->cache[$origin] = [$rules, $now + $ttl];

        return $rules;
    }

    /** @return array{0: RobotsTxt, 1: int} */
    private function load(string $robotsUrl): array
    {
        try {
            $response = ($this->fetch)($robotsUrl);
        } catch (UnsafeUrlException | HostNotAllowedException $policyViolation) {
            throw $policyViolation;
        } catch (Throwable) {
            return [RobotsTxt::disallowAll(), $this->failureTtlSeconds];
        }

        if ($response->status >= 200 && $response->status < 300) {
            return [RobotsTxt::parse($response->body), $this->ttlSeconds];
        }
        if ($response->status >= 400 && $response->status < 500) {
            return [RobotsTxt::allowAll(), $this->ttlSeconds];
        }

        return [RobotsTxt::disallowAll(), $this->failureTtlSeconds];
    }
}
