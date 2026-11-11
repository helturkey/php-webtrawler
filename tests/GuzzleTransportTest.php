<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use HElturkey\WebTrawler\Exception\ResponseTooLargeException;
use HElturkey\WebTrawler\Exception\UnsafeUrlException;
use HElturkey\WebTrawler\Http\FetchOptions;
use HElturkey\WebTrawler\Http\GuzzleTransport;
use PHPUnit\Framework\TestCase;

final class GuzzleTransportTest extends TestCase
{
    private function transport(Response ...$responses): GuzzleTransport
    {
        return new GuzzleTransport(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
    }

    public function test_it_returns_status_headers_and_body(): void
    {
        $response = $this->transport(new Response(404, ['X-A' => 'b'], 'gone'))
            ->send('GET', 'https://example.test/x', (new FetchOptions())->toTransportOptions());

        self::assertSame(404, $response->status);
        self::assertSame('gone', $response->body);
        self::assertSame('b', $response->header('x-a'));
    }

    public function test_an_oversized_content_length_raises_the_domain_exception_unwrapped(): void
    {
        $this->expectException(ResponseTooLargeException::class);
        $this->transport(new Response(200, ['Content-Length' => '5000'], str_repeat('x', 5000)))
            ->send('GET', 'https://example.test/', (new FetchOptions(maxBodyBytes: 1000))->toTransportOptions());
    }

    public function test_initial_private_addresses_are_blocked_when_guarded_directly(): void
    {
        $this->expectException(UnsafeUrlException::class);
        $this->transport(new Response(200, [], 'not fetched'))
            ->send('GET', 'http://127.0.0.1/admin', ['block_private_networks' => true]);
    }

    public function test_redirects_to_private_addresses_are_blocked_when_enabled(): void
    {
        $transport = $this->transport(
            new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
            new Response(200, [], 'secret'),
        );

        $this->expectException(UnsafeUrlException::class);
        $transport->send('GET', 'https://example.test/', (new FetchOptions(blockPrivateNetworks: true))->toTransportOptions());
    }

    public function test_boolean_redirect_option_is_wrapped_with_the_security_guard(): void
    {
        $transport = $this->transport(
            new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
            new Response(200, [], 'should-not-be-read'),
        );

        $this->expectException(UnsafeUrlException::class);
        $transport->send('GET', 'https://example.test/', [
            'allow_redirects' => true,
            'block_private_networks' => true,
        ]);
    }

    public function test_malformed_security_options_fail_instead_of_disabling_guards(): void
    {
        $transport = $this->transport(new Response(200, [], 'ok'));
        try {
            $transport->send('GET', 'https://example.test/', ['block_private_networks' => 'false']);
            self::fail('The private-network option must be boolean.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('must be a boolean', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_body_bytes');
        $transport->send('GET', 'https://example.test/', ['max_body_bytes' => '2048']);
    }

    public function test_direct_transport_timeout_options_must_be_finite_and_non_negative(): void
    {
        $transport = $this->transport(new Response(200, [], 'ok'));
        foreach ([INF, NAN, -1.0, '5'] as $timeout) {
            try {
                $transport->send('GET', 'https://example.test/', ['timeout' => $timeout]);
                self::fail('Invalid Guzzle timeout values must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('timeout', $exception->getMessage());
            }
        }
    }

    public function test_redirects_to_public_addresses_still_work_when_guarded(): void
    {
        $response = $this->transport(
            new Response(302, ['Location' => 'https://1.1.1.1/ok']),
            new Response(200, [], 'fine'),
        )->send('GET', 'https://8.8.8.8/start', (new FetchOptions(blockPrivateNetworks: true))->toTransportOptions());

        self::assertSame('fine', $response->body);
    }
}
