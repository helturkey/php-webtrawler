<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Parsing;

use DOMDocument;
use DOMElement;

/**
 * Reads sitemaps.org XML (a URL set or a sitemap index), optionally gzip-compressed.
 *
 * Hardened for untrusted input: no network access while parsing, DOCTYPE entity declarations are refused,
 * decompression is capped (a gzip bomb cannot exhaust memory), and at most 50,000 entries are read, which is
 * the protocol's own limit.
 */
final class SitemapParser
{
    public const MAX_DECOMPRESSED_BYTES = 52_428_800;
    public const MAX_ENTRIES = 50_000;

    /** Cheap pre-check so ordinary pages are never fed to an XML parser. */
    public static function looksLikeSitemap(string $body): bool
    {
        if (str_starts_with($body, "\x1f\x8b")) {
            return true;
        }
        $head = strtolower(substr(ltrim($body, "\xEF\xBB\xBF \t\r\n"), 0, 512));

        return str_starts_with($head, '<?xml') || str_starts_with($head, '<urlset') || str_starts_with($head, '<sitemapindex');
    }

    /** @return array{type: 'index'|'urlset', entries: list<array{loc: string, lastmod: ?string}>}|null null when the body is not a sitemap */
    public static function parse(string $body): ?array
    {
        if (str_starts_with($body, "\x1f\x8b")) {
            $decoded = function_exists('gzdecode') ? @gzdecode($body, self::MAX_DECOMPRESSED_BYTES) : false;
            if ($decoded === false) {
                return null;
            }
            $body = $decoded;
        }
        if ($body === '' || stripos($body, '<!ENTITY') !== false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $loaded = $document->loadXML($body, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }
        $root = $loaded ? $document->documentElement : null;
        if (!$root instanceof DOMElement) {
            return null;
        }

        $type = match (strtolower((string) $root->localName)) {
            'urlset' => 'urlset',
            'sitemapindex' => 'index',
            default => null,
        };
        if ($type === null) {
            return null;
        }
        $entryName = $type === 'index' ? 'sitemap' : 'url';

        $entries = [];
        foreach ($root->childNodes as $node) {
            if (!$node instanceof DOMElement || strtolower((string) $node->localName) !== $entryName) {
                continue;
            }
            $loc = null;
            $lastmod = null;
            foreach ($node->childNodes as $field) {
                if (!$field instanceof DOMElement) {
                    continue;
                }
                $name = strtolower((string) $field->localName);
                if ($name === 'loc') {
                    $loc = trim($field->textContent);
                } elseif ($name === 'lastmod') {
                    $lastmod = trim($field->textContent) ?: null;
                }
            }
            if ($loc !== null && $loc !== '') {
                $entries[] = ['loc' => $loc, 'lastmod' => $lastmod];
                if (count($entries) >= self::MAX_ENTRIES) {
                    break;
                }
            }
        }

        return ['type' => $type, 'entries' => $entries];
    }
}
