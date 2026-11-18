<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Parsing;

use HElturkey\WebTrawler\Parsing\SitemapParser;
use PHPUnit\Framework\TestCase;

final class SitemapParserTest extends TestCase
{
    private const URLSET = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc> https://blog.test/post/1 </loc><lastmod>2026-01-02</lastmod></url>
  <url><loc>https://blog.test/post/2</loc></url>
  <url><lastmod>2026-01-03</lastmod></url>
</urlset>
XML;

    public function test_it_reads_a_url_set(): void
    {
        $parsed = SitemapParser::parse(self::URLSET);

        self::assertSame('urlset', $parsed['type']);
        self::assertSame([
            ['loc' => 'https://blog.test/post/1', 'lastmod' => '2026-01-02'],
            ['loc' => 'https://blog.test/post/2', 'lastmod' => null],
        ], $parsed['entries'], 'Entries without a <loc> are ignored.');
    }

    public function test_it_reads_a_sitemap_index(): void
    {
        $xml = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://blog.test/posts.xml</loc></sitemap></sitemapindex>';

        $parsed = SitemapParser::parse($xml);

        self::assertSame('index', $parsed['type']);
        self::assertSame('https://blog.test/posts.xml', $parsed['entries'][0]['loc']);
    }

    public function test_gzip_sitemaps_are_decompressed(): void
    {
        $parsed = SitemapParser::parse((string) gzencode(self::URLSET));

        self::assertSame(2, count($parsed['entries'] ?? []));
    }

    public function test_entity_declarations_and_foreign_documents_are_refused(): void
    {
        $bomb = '<?xml version="1.0"?><!DOCTYPE urlset [<!ENTITY a "aaaa">]><urlset><url><loc>&a;</loc></url></urlset>';

        self::assertNull(SitemapParser::parse($bomb));
        self::assertNull(SitemapParser::parse('<?xml version="1.0"?><rss><channel/></rss>'));
        self::assertNull(SitemapParser::parse('<html><body>not xml</body></html>'));
        self::assertNull(SitemapParser::parse(''));
        self::assertNull(SitemapParser::parse("\x1f\x8bnot really gzip"));
    }

    public function test_looks_like_sitemap_is_a_cheap_pre_check(): void
    {
        self::assertTrue(SitemapParser::looksLikeSitemap(self::URLSET));
        self::assertTrue(SitemapParser::looksLikeSitemap("\xEF\xBB\xBF  <urlset></urlset>"));
        self::assertTrue(SitemapParser::looksLikeSitemap((string) gzencode('x')));
        self::assertFalse(SitemapParser::looksLikeSitemap('<!doctype html><html></html>'));
    }
}
