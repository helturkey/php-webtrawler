<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Browser\BrowserRequestGuard;
use PHPUnit\Framework\TestCase;

/**
 * The decision logic of the browser guard. Routing alone only sees original requests (Chromium follows
 * redirects without calling the route handler again), so these tests model exactly that gap and check that
 * the post-navigation verification closes it. They do not launch Chromium.
 */
final class BrowserGuardVerificationTest extends TestCase
{
    public function test_a_public_url_that_redirects_to_a_private_address_is_caught_after_navigation(): void
    {
        $guard = new BrowserRequestGuard();

        // The route handler only ever sees the original, public request ...
        $guard->markRouted('https://8.8.8.8/start');
        self::assertTrue($guard->allows('https://8.8.8.8/start'));
        // ... while Chromium also requested the redirect target without routing it.
        $guard->observe('https://8.8.8.8/start');
        $guard->observe('http://169.254.169.254/latest/meta-data?token=SECRET');

        $problems = $guard->verifyNavigation(['http://169.254.169.254/latest/meta-data?token=SECRET', 'https://8.8.8.8/start'], null, 'http://169.254.169.254/latest/meta-data?token=SECRET');

        self::assertNotSame([], $problems);
        self::assertStringContainsString('169.254.169.254', implode(' ', $problems));
        self::assertStringNotContainsString('SECRET', implode(' ', $problems), 'Problems are reported without secrets.');
    }

    public function test_a_clean_navigation_has_no_problems_and_blocked_requests_are_not_double_reported(): void
    {
        $guard = new BrowserRequestGuard();
        foreach (['https://8.8.8.8/page', 'https://8.8.4.4/app.js'] as $url) {
            $guard->markRouted($url);
            $guard->observe($url);
            self::assertTrue($guard->allows($url));
        }
        // The route handler already aborted this one; it must not turn a successful render into a failure.
        $guard->markRouted('http://10.0.0.1/tracker.gif');
        $guard->observe('http://10.0.0.1/tracker.gif');
        self::assertFalse($guard->allows('http://10.0.0.1/tracker.gif'));

        self::assertSame([], $guard->verifyNavigation(['https://8.8.8.8/page'], '8.8.8.8', 'https://8.8.8.8/page'));
    }

    public function test_the_address_the_browser_actually_connected_to_is_verified(): void
    {
        $guard = new BrowserRequestGuard();
        $guard->markRouted('https://rebind.example/');
        $guard->observe('https://rebind.example/');

        // The name looked public when checked, but the connection went to loopback (DNS rebinding).
        $guard->allows('https://8.8.8.8/'); // warm the cache with an unrelated public decision
        foreach (['127.0.0.1', '::1', '10.1.2.3', '169.254.169.254', '::ffff:7f00:1', 'fd00::5'] as $address) {
            $problems = $guard->verifyNavigation([], $address);
            self::assertStringContainsString('non-public address', implode(' ', $problems), $address);
        }
        self::assertSame([], $guard->verifyNavigation([], '8.8.8.8'));
        self::assertSame([], $guard->verifyNavigation([], null), 'An unreported address does not by itself fail the render.');
    }

    public function test_the_redirect_chain_of_the_main_frame_is_checked_hop_by_hop(): void
    {
        $guard = new BrowserRequestGuard();

        $problems = $guard->verifyNavigation(['https://8.8.8.8/final', 'http://127.0.0.1:9222/json', 'https://8.8.8.8/start'], null);

        self::assertCount(1, $problems);
        self::assertStringContainsString('127.0.0.1', $problems[0]);
    }

    public function test_browser_allow_list_applies_to_original_and_observed_requests(): void
    {
        $guard = new BrowserRequestGuard(false, ['example.test']);
        self::assertTrue($guard->allows('https://api.example.test/page'));
        self::assertFalse($guard->allows('https://evil.test/page'));

        $guard->observe('https://evil.test/redirect-target');
        $problems = $guard->verifyNavigation([], null, 'https://evil.test/redirect-target');
        self::assertNotSame([], $problems);
        self::assertStringContainsString('evil.test', implode(' ', $problems));
    }

    public function test_it_fails_closed_when_there_are_too_many_requests_to_inspect(): void
    {
        $guard = new BrowserRequestGuard();
        for ($i = 0; $i < 10_050; $i++) {
            $guard->observe('https://8.8.8.8/r/' . $i);
        }

        self::assertContains('too many requests to verify', $guard->verifyNavigation([], null));
    }

    public function test_non_network_and_non_http_schemes_are_handled(): void
    {
        $guard = new BrowserRequestGuard();
        $guard->observe('data:text/html,hi');
        $guard->observe('blob:https://8.8.8.8/uuid');
        self::assertSame([], $guard->verifyNavigation([], null));

        $guard->observe('file:///etc/passwd');
        self::assertNotSame([], $guard->verifyNavigation([], null));
    }
}
