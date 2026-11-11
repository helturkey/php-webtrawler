<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Http\FetchResponse;
use HElturkey\WebTrawler\Parsing\HtmlDecoder;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\TestCase;

final class HtmlDecoderTest extends TestCase
{
    private function page(string $body, array $headers = []): Page
    {
        $response = new FetchResponse(200, $headers, $body, 'https://example.test/', 'https://example.test/', 1.0);

        return Page::fromResponse($response, new InMemorySnapshotStore(), new AdaptiveMatcher());
    }

    private function encode(string $html, string $charset): string
    {
        $encoded = iconv('UTF-8', $charset, $html);
        self::assertIsString($encoded);

        return $encoded;
    }

    public function test_it_honors_the_http_charset_header_for_windows_1256(): void
    {
        $body = $this->encode('<html><body><h1>مرحبا</h1></body></html>', 'WINDOWS-1256');
        $page = $this->page($body, ['Content-Type' => ['text/html; charset=windows-1256']]);

        self::assertSame('مرحبا', $page->css('h1')->first()?->text());
    }

    public function test_it_honors_a_meta_charset_without_a_header(): void
    {
        $body = $this->encode('<html><head><meta charset="windows-1256"></head><body><h1>مرحبا</h1></body></html>', 'WINDOWS-1256');

        self::assertSame('مرحبا', $this->page($body)->css('h1')->first()?->text());
    }

    public function test_it_decodes_latin1_and_strips_a_utf8_bom(): void
    {
        $latin = $this->encode('<html><body><h1>café</h1></body></html>', 'ISO-8859-1');
        self::assertSame('café', $this->page($latin, ['Content-Type' => ['text/html; charset=ISO-8859-1']])->css('h1')->first()?->text());

        $bom = "\xEF\xBB\xBF<html><body><h1>café</h1></body></html>";
        self::assertSame('café', $this->page($bom)->css('h1')->first()?->text());
    }

    public function test_a_stale_meta_charset_does_not_re_decode_utf8_text(): void
    {
        $html = '<html><head><meta charset="windows-1256"></head><body><h1>مرحبا</h1></body></html>';
        $page = Page::fromHtml($html, 'https://example.test/', new InMemorySnapshotStore(), new AdaptiveMatcher());

        self::assertSame('مرحبا', $page->css('h1')->first()?->text());
    }

    public function test_invalid_utf8_never_yields_invalid_output(): void
    {
        self::assertTrue(preg_match('//u', HtmlDecoder::decode("<p>a\xB1b</p>")) === 1);
        self::assertSame('', HtmlDecoder::decode(''));
    }

    public function test_an_explicit_encoding_overrides_a_wrong_header_and_meta(): void
    {
        $body = $this->encode('<html><head><meta charset="utf-8"></head><body><h1>مرحبا</h1></body></html>', 'WINDOWS-1256');
        $response = new FetchResponse(200, ['Content-Type' => ['text/html; charset=utf-8']], $body, 'https://example.test/', 'https://example.test/', 1.0);

        $page = Page::fromResponse($response, new InMemorySnapshotStore(), new AdaptiveMatcher(), 'windows-1256');

        self::assertSame('مرحبا', $page->css('h1')->first()?->text());
    }

    public function test_explicit_utf8_ignores_a_misleading_header(): void
    {
        $response = new FetchResponse(200, ['Content-Type' => ['text/html; charset=windows-1256']], '<h1>مرحبا</h1>', 'https://example.test/', 'https://example.test/', 1.0);

        $page = Page::fromResponse($response, new InMemorySnapshotStore(), new AdaptiveMatcher(), 'utf-8');

        self::assertSame('مرحبا', $page->css('h1')->first()?->text());
    }

    public function test_an_unsupported_explicit_encoding_fails_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HtmlDecoder::decodeAs('<p>x</p>', 'not-a-charset');
    }

    public function test_browser_html_is_never_decoded_a_second_time(): void
    {
        $driver = new class () implements \HElturkey\WebTrawler\Contracts\BrowserDriver {
            public function render(string $url, array $options = []): \HElturkey\WebTrawler\Browser\BrowserResult
            {
                // Chromium already returns decoded Unicode, but the site's headers still say windows-1256.
                return new \HElturkey\WebTrawler\Browser\BrowserResult(
                    html: '<html><head><meta charset="windows-1256"></head><body><h1>مرحبا</h1></body></html>',
                    requestedUrl: $url,
                    finalUrl: $url,
                    headers: ['Content-Type' => ['text/html; charset=windows-1256']],
                );
            }
        };

        $page = \HElturkey\WebTrawler\Scraper::create(snapshotStore: new InMemorySnapshotStore())->fetchRendered('https://example.test/', $driver);

        self::assertSame('مرحبا', $page->css('h1')->first()?->text());
    }
}
