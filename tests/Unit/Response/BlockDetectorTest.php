<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Response;

use HElturkey\WebTrawler\Response\BlockDetector;
use HElturkey\WebTrawler\Response\FetchResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlockDetectorTest extends TestCase
{
    /** @param array<string, list<string>> $headers */
    private static function response(int $status, string $body = '', array $headers = []): FetchResponse
    {
        return new FetchResponse($status, $headers, $body, 'https://a.test/', 'https://a.test/', 1.0);
    }

    public function test_an_ordinary_page_is_not_a_block(): void
    {
        $detector = BlockDetector::default();

        self::assertNull($detector->inspect(self::response(200, '<html><body><h1>Hello</h1></body></html>')));
        self::assertNull($detector->inspect(self::response(404, '<h1>Not found</h1>')));
    }

    public function test_the_cloudflare_challenge_header_is_enough(): void
    {
        $signal = BlockDetector::default()->inspect(self::response(403, '', ['cf-mitigated' => ['challenge']]));

        self::assertSame('cloudflare', $signal?->vendor);
    }

    public function test_rate_limiting_is_reported(): void
    {
        $signal = BlockDetector::default()->inspect(self::response(429));

        self::assertSame('rate-limit', $signal?->vendor);
        self::assertSame(429, $signal?->status);
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function vendorPages(): iterable
    {
        yield 'cloudflare interstitial' => [403, '<title>Just a moment...</title>', 'cloudflare'];
        yield 'cloudflare error page' => [503, '<div id="cf-error-details">Cloudflare Ray ID: 123</div>', 'cloudflare'];
        yield 'imperva' => [403, 'Request unsuccessful. Incapsula incident ID: 1-2', 'imperva'];
        yield 'perimeterx' => [403, '<div id="px-captcha"></div>', 'perimeterx'];
        yield 'datadome' => [403, '<script src="https://geo.captcha-delivery.com/c.js"></script>', 'datadome'];
        yield 'generic captcha' => [403, '<p>Please verify you are human</p>', 'captcha'];
    }

    #[DataProvider('vendorPages')]
    public function test_known_challenge_pages_are_recognised(int $status, string $body, string $vendor): void
    {
        self::assertSame($vendor, BlockDetector::default()->inspect(self::response($status, $body))?->vendor);
    }

    public function test_a_login_form_with_a_captcha_widget_is_not_a_block(): void
    {
        $html = '<form><div class="g-recaptcha"></div><div class="cf-turnstile"></div></form>';

        self::assertNull(BlockDetector::default()->inspect(self::response(200, $html)));
    }

    public function test_a_tiny_200_page_that_is_the_cloudflare_interstitial_is_a_block(): void
    {
        $signal = BlockDetector::default()->inspect(self::response(200, '<title>Just a moment...</title><script>window._cf_chl_opt={}</script>'));

        self::assertSame('cloudflare', $signal?->vendor);
    }

    public function test_a_large_page_mentioning_the_challenge_phrase_is_not_flagged_on_200(): void
    {
        $body = str_repeat('<p>article text</p>', 3_000) . 'Just a moment...';

        self::assertNull(BlockDetector::default()->inspect(self::response(200, $body)));
    }

    public function test_a_plain_403_is_only_a_block_when_requested(): void
    {
        self::assertNull(BlockDetector::default()->inspect(self::response(403, 'forbidden')));
        self::assertSame('generic', (new BlockDetector(treatForbiddenAsBlocked: true))->inspect(self::response(403, 'forbidden'))?->vendor);
    }
}
