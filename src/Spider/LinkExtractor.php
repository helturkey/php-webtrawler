<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Spider;

use GuzzleHttp\Psr7\Uri;
use HElturkey\WebTrawler\Parsing\Page;
use InvalidArgumentException;
use Throwable;

/**
 * Finds the links on a page worth following: absolute http(s) URLs without fragments, filtered by regular
 * expressions, domains and file extensions.
 *
 * ```php
 * $products = new LinkExtractor(allow: ['#/product/\d+#'], restrictCss: 'main');
 * foreach ($products->extract($page) as $url) { yield SpiderRequest::get($url); }
 * ```
 *
 * Patterns are complete PCRE patterns including delimiters and are validated when the extractor is built.
 * They are developer-supplied: do not build them from untrusted input.
 */
final readonly class LinkExtractor
{
    /** @var list<string> Static assets, media and archives that are rarely worth crawling as pages. */
    public const DEFAULT_DENY_EXTENSIONS = [
        '7z', 'zip', 'rar', 'gz', 'tgz', 'tar', 'bz2', 'xz', 'iso', 'exe', 'msi', 'dmg', 'apk',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'tif', 'tiff',
        'mp3', 'm4a', 'wav', 'ogg', 'flac', 'mp4', 'm4v', 'mov', 'avi', 'wmv', 'mkv', 'webm',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'css', 'js', 'woff', 'woff2', 'ttf', 'otf', 'eot',
    ];

    /**
     * @param list<string> $allow A link must match at least one of these (empty: any link).
     * @param list<string> $deny A link matching any of these is dropped.
     * @param list<string> $allowDomains Hosts (and their subdomains) a link may point to (empty: any).
     * @param list<string> $denyDomains Hosts (and their subdomains) that are always dropped.
     * @param list<string> $denyExtensions File extensions (without dot) that are dropped.
     * @param string|null $restrictCss Only look for links inside elements matching this CSS selector.
     * @param list<string> $tags Elements that carry links.
     * @param list<string> $attributes Attributes that hold the URL.
     * @param bool $skipNofollow Drop links whose rel attribute contains "nofollow".
     * @param int $limit Maximum links returned per page (a guard against hostile pages).
     */
    public function __construct(
        private array $allow = [],
        private array $deny = [],
        private array $allowDomains = [],
        private array $denyDomains = [],
        private array $denyExtensions = self::DEFAULT_DENY_EXTENSIONS,
        private ?string $restrictCss = null,
        private array $tags = ['a', 'area'],
        private array $attributes = ['href'],
        private bool $skipNofollow = false,
        private int $limit = 5_000,
    ) {
        foreach ([...$allow, ...$deny] as $pattern) {
            if (!is_string($pattern) || @preg_match($pattern, '') === false) {
                throw new InvalidArgumentException('Invalid link pattern: ' . (is_string($pattern) ? $pattern : get_debug_type($pattern)));
            }
        }
        if ($tags === [] || $attributes === [] || $limit < 1) {
            throw new InvalidArgumentException('A link extractor needs at least one tag, one attribute and a positive limit.');
        }
        foreach ([...$tags, ...$attributes] as $name) {
            if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9:_-]*$/', $name) !== 1) {
                throw new InvalidArgumentException('Tags and attributes must be plain names.');
            }
        }
    }

    /** @return list<string> Absolute, de-duplicated URLs in document order. */
    public function extract(Page $page): array
    {
        $selector = [];
        foreach ($this->tags as $tag) {
            foreach ($this->attributes as $attribute) {
                $selector[] = sprintf('%s[%s]', $tag, $attribute);
            }
        }
        $selector = implode(', ', $selector);

        $elements = [];
        if ($this->restrictCss === null) {
            $elements = $page->css($selector)->all();
        } else {
            foreach ($page->css($this->restrictCss) as $scope) {
                foreach ($scope->css($selector) as $element) {
                    $elements[] = $element;
                }
            }
        }

        $own = $this->withoutFragment($page->url());
        $links = [];
        foreach ($elements as $element) {
            if ($this->skipNofollow && in_array('nofollow', preg_split('/\s+/', strtolower((string) $element->attr('rel'))) ?: [], true)) {
                continue;
            }
            foreach ($this->attributes as $attribute) {
                $raw = trim((string) $element->attr($attribute));
                if ($raw === '') {
                    continue;
                }
                $url = $this->absolute($page, $raw);
                if ($url === null || $url === $own || isset($links[$url]) || !$this->matches($url)) {
                    continue;
                }
                $links[$url] = true;
                if (count($links) >= $this->limit) {
                    break 2;
                }
            }
        }

        return array_map('strval', array_keys($links));
    }

    /** Whether an absolute URL passes the allow/deny/domain/extension rules (page content is not consulted). */
    public function matches(string $url): bool
    {
        try {
            $uri = new Uri($url);
        } catch (Throwable) {
            return false;
        }
        if (!in_array(strtolower($uri->getScheme()), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($uri->getHost());
        if ($this->allowDomains !== [] && !self::hostIn($host, $this->allowDomains)) {
            return false;
        }
        if (self::hostIn($host, $this->denyDomains)) {
            return false;
        }

        $extension = strtolower(pathinfo($uri->getPath(), PATHINFO_EXTENSION));
        if ($extension !== '' && in_array($extension, $this->denyExtensions, true)) {
            return false;
        }

        foreach ($this->deny as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return false;
            }
        }
        if ($this->allow === []) {
            return true;
        }
        foreach ($this->allow as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return true;
            }
        }

        return false;
    }

    private function absolute(Page $page, string $raw): ?string
    {
        try {
            $resolved = $page->resolve($raw);
            $uri = new Uri($resolved);
        } catch (Throwable) {
            return null;
        }
        if (!in_array(strtolower($uri->getScheme()), ['http', 'https'], true) || $uri->getHost() === '') {
            return null;
        }

        return (string) $uri->withFragment('');
    }

    private function withoutFragment(string $url): string
    {
        try {
            return (string) (new Uri($url))->withFragment('');
        } catch (Throwable) {
            return $url;
        }
    }

    /** @param list<string> $domains */
    private static function hostIn(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain, ". \t"));
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                return true;
            }
        }

        return false;
    }
}
