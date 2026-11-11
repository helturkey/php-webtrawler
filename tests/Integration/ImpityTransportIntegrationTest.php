<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Integration;

use HElturkey\WebTrawler\Exception\HostNotAllowedException;
use HElturkey\WebTrawler\Exception\ResponseTooLargeException;
use HElturkey\WebTrawler\Http\ImpityTransport;
use HElturkey\WebTrawler\Tests\Support\LocalServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('impity')]
final class ImpityTransportIntegrationTest extends TestCase
{
    private ?LocalServer $server = null;

    protected function setUp(): void
    {
        if (!class_exists(\Dekor\Impity\Client::class)) {
            self::markTestSkipped('Install the optional transport with composer require dekor/php-impity.');
        }

        $binary = getenv('IMPITY_CURL_IMPERSONATE_PATH');
        if (!is_string($binary) || trim($binary) === '') {
            self::markTestSkipped('Set IMPITY_CURL_IMPERSONATE_PATH to an executable curl-impersonate binary.');
        }
        if (!is_file($binary) || !is_executable($binary)) {
            self::fail(sprintf(
                'IMPITY_CURL_IMPERSONATE_PATH points to a missing or non-executable file: %s',
                $binary,
            ));
        }

        $this->server = LocalServer::start();
        if ($this->server === null) {
            self::markTestSkipped('The local PHP HTTP fixture could not be started.');
        }
    }

    public function test_real_impity_client_fetches_and_follows_a_local_redirect(): void
    {
        $response = (new ImpityTransport())->send('GET', $this->server->baseUrl . '/redir', [
            'max_body_bytes' => 1_000_000,
        ]);

        self::assertSame(200, $response->status);
        self::assertSame('<h1>hi</h1>', $response->body);
        self::assertSame($this->server->baseUrl . '/small', $response->effectiveUrl);
    }

    public function test_real_impity_client_checks_the_host_policy_before_a_redirect(): void
    {
        $this->expectException(HostNotAllowedException::class);

        (new ImpityTransport())->send('GET', $this->server->baseUrl . '/redir-other-host', [
            'allowed_hosts' => ['127.0.0.1'],
        ]);
    }

    public function test_real_impity_client_enforces_the_response_body_limit(): void
    {
        $this->expectException(ResponseTooLargeException::class);

        (new ImpityTransport())->send('GET', $this->server->baseUrl . '/biglen', [
            'max_body_bytes' => 1_000_000,
        ]);
    }
}
