<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Integration\Live\Transport;

use HElturkey\WebTrawler\Proxy\ProxyPool;
use HElturkey\WebTrawler\Proxy\ProxyTransport;
use HElturkey\WebTrawler\Retry\RetryPolicy;
use HElturkey\WebTrawler\Retry\RetryingTransport;
use HElturkey\WebTrawler\Tests\Support\LiveTargets;
use HElturkey\WebTrawler\Tests\Support\LiveTestCase;
use HElturkey\WebTrawler\Transport\Drivers\AmpTransport;
use HElturkey\WebTrawler\Transport\Drivers\GuzzleTransport;
use HElturkey\WebTrawler\Transport\Drivers\ImpityTransport;
use HElturkey\WebTrawler\Transport\Drivers\RnetTransport;
use HElturkey\WebTrawler\Transport\TransportRegistry;
use PHPUnit\Framework\Attributes\Group;

#[Group('live')]
final class LiveTransportServicesTest extends LiveTestCase
{
    public function test_guzzle_transport_fetches_the_quotes_target(): void
    {
        $response = (new GuzzleTransport())->send('GET', LiveTargets::QUOTES_URL, [
            'timeout' => 45,
            'connect_timeout' => 15,
            'allowed_hosts' => [LiveTargets::QUOTES_HOST],
            'max_body_bytes' => 8 * 1024 * 1024,
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('class="quote"', $response->body);
        self::assertTrue($response->isSuccessful());
    }

    public function test_retrying_transport_delegates_a_live_books_request(): void
    {
        $transport = new RetryingTransport(new GuzzleTransport(), RetryPolicy::none());
        $response = $transport->send('GET', LiveTargets::BOOKS_URL, [
            'timeout' => 45,
            'connect_timeout' => 15,
            'allowed_hosts' => [LiveTargets::BOOKS_HOST],
            'max_body_bytes' => 8 * 1024 * 1024,
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('product_pod', $response->body);
    }

    public function test_default_transport_registry_creates_a_working_guzzle_transport(): void
    {
        $registry = TransportRegistry::withDefaults();
        $transport = $registry->create('guzzle');
        $response = $transport->send('GET', LiveTargets::BOOKS_URL, [
            'timeout' => 45,
            'allowed_hosts' => [LiveTargets::BOOKS_HOST],
            'max_body_bytes' => 8 * 1024 * 1024,
        ]);

        self::assertContains('guzzle', $registry->names());
        self::assertSame(200, $response->status);
        self::assertStringContainsString('product_pod', $response->body);
    }

    public function test_amp_transport_fetches_the_quotes_target_when_the_optional_driver_is_installed(): void
    {
        if (!class_exists(\Amp\Http\Client\HttpClientBuilder::class) || !class_exists(\Revolt\EventLoop::class)) {
            self::markTestSkipped('Install amphp/http-client 5 and revolt/event-loop to test AmpTransport.');
        }

        $response = (new AmpTransport())->send('GET', LiveTargets::QUOTES_URL, [
            'timeout' => 45,
            'allowed_hosts' => [LiveTargets::QUOTES_HOST],
            'max_body_bytes' => 8 * 1024 * 1024,
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('class="quote"', $response->body);
    }

    public function test_rnet_transport_fetches_the_books_target_when_the_extension_is_installed(): void
    {
        if (!RnetTransport::isAvailable()) {
            self::markTestSkipped('Install the php-rnet extension to test RnetTransport against public targets.');
        }

        $response = (new RnetTransport())->send('GET', LiveTargets::BOOKS_URL, [
            'timeout' => 45,
            'allowed_hosts' => [LiveTargets::BOOKS_HOST],
            'max_body_bytes' => 8 * 1024 * 1024,
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('product_pod', $response->body);
    }

    public function test_impity_transport_fetches_the_quotes_target_when_its_runtime_is_configured(): void
    {
        if (!class_exists(\Dekor\Impity\Client::class) || !enum_exists(\Dekor\Impity\ImpityBrowser::class)) {
            self::markTestSkipped('Install dekor/php-impity to test ImpityTransport against public targets.');
        }

        $binary = getenv('IMPITY_CURL_IMPERSONATE_PATH');
        if (!is_string($binary) || $binary === '' || !is_file($binary) || !is_executable($binary)) {
            self::markTestSkipped('Set IMPITY_CURL_IMPERSONATE_PATH to an executable curl-impersonate binary.');
        }

        $response = (new ImpityTransport())->send('GET', LiveTargets::QUOTES_URL, [
            'timeout' => 45,
            'allowed_hosts' => [LiveTargets::QUOTES_HOST],
            'max_body_bytes' => 8 * 1024 * 1024,
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('class="quote"', $response->body);
    }

    public function test_proxy_transport_fetches_books_through_an_explicitly_configured_test_proxy(): void
    {
        $proxy = getenv('SCRAPER_TEST_PROXY');
        if (!is_string($proxy) || trim($proxy) === '') {
            self::markTestSkipped('Set SCRAPER_TEST_PROXY to an HTTP(S) or SOCKS proxy to exercise ProxyTransport live.');
        }

        $transport = new ProxyTransport(new GuzzleTransport(), new ProxyPool([trim($proxy)]));
        $response = $transport->send('GET', LiveTargets::BOOKS_URL, [
            'timeout' => 45,
            'allowed_hosts' => [LiveTargets::BOOKS_HOST],
            'max_body_bytes' => 8 * 1024 * 1024,
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('product_pod', $response->body);
    }
}
