<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Exception\UnsafeUrlException;
use HElturkey\WebTrawler\Http\UrlGuard;
use HElturkey\WebTrawler\Http\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class UrlSafetyTest extends TestCase
{
    public function test_it_normalizes_internationalized_urls(): void
    {
        self::assertSame('https://example.test/a%20b?q=%C3%BC', UrlNormalizer::normalize('https://example.test/a b?q=ü'));
        self::assertSame('https://example.test/%D9%83%D8%AA%D8%A7%D8%A8', UrlNormalizer::normalize('https://example.test/كتاب'));
        self::assertSame('http://example.test/x', UrlNormalizer::normalize('  HTTP://Example.TEST:80/x '));
        self::assertSame('https://[::1]:8080/', UrlNormalizer::normalize('https://[::1]:8080/'));
    }

    public function test_it_converts_idn_hosts_to_punycode_when_intl_is_available(): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('ext-intl is not installed.');
        }

        self::assertSame('https://xn--mgbh0fb.com/', UrlNormalizer::normalize('https://مثال.com/'));
    }

    public function test_it_rejects_malformed_and_non_http_urls(): void
    {
        foreach (['', 'mailto:a@b.test', 'file:///etc/passwd', '//example.test', 'https://exa mple.test/', 'https://example.test:99999/', "https://example.test/\nfoo", "https://exa\tmple.test/"] as $url) {
            try {
                UrlNormalizer::normalize($url);
                self::fail('Expected rejection of ' . json_encode($url));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_guard_blocks_non_public_addresses(): void
    {
        $blocked = [
            'http://127.0.0.1/', 'http://[::1]/', 'http://169.254.169.254/latest/meta-data', 'http://10.1.2.3/',
            'http://192.168.0.1/', 'http://172.16.0.1/', 'http://100.64.0.1/', 'http://0.0.0.0/', 'http://[fd00::1]/',
            'http://[::ffff:7f00:1]/', 'http://[64:ff9b::7f00:1]/', 'http://2130706433/', 'http://0x7f.1/', 'http://localhost/',
        ];
        foreach ($blocked as $url) {
            try {
                UrlGuard::assertPublic($url);
                self::fail("$url should be blocked");
            } catch (UnsafeUrlException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_guard_allows_public_literals(): void
    {
        UrlGuard::assertPublic('http://8.8.8.8/');
        UrlGuard::assertPublic('https://[2001:4860:4860::8888]/');

        self::assertTrue(UrlGuard::isPublicIp('1.1.1.1'));
        self::assertFalse(UrlGuard::isPublicIp('not-an-ip'));
    }
}
