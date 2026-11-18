<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Spider;

use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Parsing\SitemapParser;

/**
 * Crawls a site from its sitemap(s) instead of by following links.
 *
 * ```php
 * final class BlogSpider extends SitemapSpider
 * {
 *     protected array $sitemapUrls = ['https://blog.example/sitemap.xml'];
 *     protected array $sitemapRules = ['#/posts/#' => 'parsePost'];
 *
 *     protected function parsePost(Page $page, SpiderRequest $request): iterable { yield [...]; }
 * }
 * ```
 *
 * Sitemap indexes are followed (limited by $sitemapFollow), gzip sitemaps are decompressed, and entries are
 * routed to a callback by the first matching pattern in $sitemapRules. A page that matches no rule is skipped,
 * so a sitemap never causes URLs to be fetched that you did not ask for. By default a sitemap may only list
 * URLs on its own host, as the sitemap protocol requires.
 */
abstract class SitemapSpider extends Spider
{
    /** @var list<string> */
    protected array $sitemapUrls = [];

    /** @var array<string, string> PCRE pattern => callback method name (public or protected). */
    protected array $sitemapRules = [];

    /** @var list<string> PCRE patterns; only matching child sitemaps of an index are followed (empty: all). */
    protected array $sitemapFollow = [];

    /** Allow a sitemap to list URLs on other hosts. Off by default. */
    protected bool $allowCrossHostEntries = false;

    /** @return iterable<SpiderRequest> */
    public function startRequests(): iterable
    {
        foreach ($this->sitemapUrls as $url) {
            yield SpiderRequest::get($url);
        }
    }

    /** @return iterable<SpiderRequest|SpiderItem|array<string, mixed>> */
    final public function parse(Page $page, SpiderRequest $request): iterable
    {
        $body = $page->response()->body ?? '';
        $sitemap = SitemapParser::looksLikeSitemap($body) ? SitemapParser::parse($body) : null;

        if ($sitemap === null) {
            foreach ($this->sitemapRules as $pattern => $callback) {
                if (preg_match((string) $pattern, $page->url()) === 1) {
                    if (!method_exists($this, $callback)) {
                        throw new \LogicException(sprintf('Sitemap callback %s::%s() does not exist.', static::class, $callback));
                    }
                    /** @var iterable<mixed> $outputs */
                    $outputs = $this->{$callback}($page, $request);
                    yield from $outputs;

                    return;
                }
            }

            return;
        }

        $host = strtolower((string) parse_url($page->url(), PHP_URL_HOST));
        foreach ($sitemap['entries'] as $entry) {
            $location = $entry['loc'];
            if (!$this->allowCrossHostEntries && strtolower((string) parse_url($location, PHP_URL_HOST)) !== $host) {
                continue;
            }

            if ($sitemap['type'] === 'index') {
                if ($this->followsSitemap($location)) {
                    yield SpiderRequest::get($location);
                }
                continue;
            }
            foreach (array_keys($this->sitemapRules) as $pattern) {
                if (preg_match((string) $pattern, $location) === 1) {
                    yield SpiderRequest::get($location);
                    break;
                }
            }
        }
    }

    private function followsSitemap(string $location): bool
    {
        if ($this->sitemapFollow === []) {
            return true;
        }
        foreach ($this->sitemapFollow as $pattern) {
            if (preg_match($pattern, $location) === 1) {
                return true;
            }
        }

        return false;
    }
}
