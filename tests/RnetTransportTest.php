<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Contracts\TransientFailure;
use HElturkey\WebTrawler\Exception\ResponseTooLargeException;
use HElturkey\WebTrawler\Exception\UnsafeUrlException;
use HElturkey\WebTrawler\Http\FetchOptions;
use HElturkey\WebTrawler\Http\RnetTransport;
use PHPUnit\Framework\TestCase;

/** Behaviour of the adapter against a fake client that mimics the documented php-rnet API. */
final class RnetTransportTest extends TestCase
{
    /** @param list<array{status: int, headers?: array<string, string>, body?: string, bytes?: bool}> $script */
    private function transport(array $script, array &$calls = []): RnetTransport
    {
        return new RnetTransport(null, static function (array $config) use (&$script, &$calls): object {
            return new class ($script, $calls) {
                /** @param list<array<string, mixed>> $script @param list<array<string, mixed>> $calls */
                public function __construct(private array &$script, private array &$calls)
                {
                }

                public function request(string $method, string $url, ?array $options): object
                {
                    $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];
                    $step = array_shift($this->script) ?? ['status' => 200];
                    if (($step['throw'] ?? null) instanceof \Throwable) {
                        throw $step['throw'];
                    }

                    return new class ($step) {
                        /** @param array<string, mixed> $step */
                        public function __construct(private array $step)
                        {
                        }

                        public function status(): int
                        {
                            return $this->step['status'];
                        }

                        public function headers(): array
                        {
                            return $this->step['headers'] ?? [];
                        }

                        public function header(string $name): ?string
                        {
                            return $this->step['headers'][strtolower($name)] ?? null;
                        }

                        public function text(): string
                        {
                            if (($this->step['bytes'] ?? false) === true) {
                                throw new \Exception('UTF-8 decode error: invalid utf-8 sequence');
                            }

                            return $this->step['body'] ?? '';
                        }

                        public function bytes(): array
                        {
                            return array_values(unpack('C*', $this->step['body'] ?? '') ?: []);
                        }
                    };
                }
            };
        });
    }

    public function test_default_fetch_options_are_accepted_without_guzzle_only_redirect_tracking(): void
    {
        $calls = [];
        $response = $this->transport([['status' => 200, 'body' => 'ok']], $calls)->send(
            'GET',
            'https://example.test/',
            (new FetchOptions())->toTransportOptions(),
        );

        self::assertSame(200, $response->status);
        self::assertCount(1, $calls);
        self::assertSame('ok', $response->body);
    }

    public function test_it_follows_redirects_itself_and_reports_the_effective_url(): void
    {
        $calls = [];
        $transport = $this->transport([
            ['status' => 302, 'headers' => ['location' => '/next']],
            ['status' => 200, 'body' => 'done'],
        ], $calls);

        $response = $transport->send('GET', 'https://example.test/start', []);

        self::assertSame('https://example.test/next', $response->effectiveUrl);
        self::assertSame('done', $response->body);
        self::assertCount(2, $calls);
    }

    public function test_it_drops_credentials_on_cross_host_redirects_and_switches_post_to_get(): void
    {
        $calls = [];
        $transport = $this->transport([
            ['status' => 302, 'headers' => ['location' => 'https://other.test/x']],
            ['status' => 200],
        ], $calls);

        $transport->send('POST', 'https://example.test/', ['headers' => ['Authorization' => 'Bearer s', 'X-Keep' => '1'], 'form_params' => ['a' => 'b']]);

        self::assertSame('GET', $calls[1]['method']);
        self::assertSame(['X-Keep' => '1'], $calls[1]['options']['headers']);
        self::assertArrayNotHasKey('form', $calls[1]['options']);
    }

    public function test_redirect_hops_are_checked_against_the_private_network_policy(): void
    {
        $transport = $this->transport([['status' => 302, 'headers' => ['location' => 'http://127.0.0.1/admin']]]);

        $this->expectException(UnsafeUrlException::class);
        $transport->send('GET', 'https://example.test/', ['block_private_networks' => true]);
    }

    public function test_same_host_but_different_port_is_a_cross_origin_redirect(): void
    {
        $calls = [];
        $transport = $this->transport([
            ['status' => 302, 'headers' => ['location' => 'https://example.test:9443/next']],
            ['status' => 200],
        ], $calls);

        $transport->send('GET', 'https://example.test:8443/start', ['headers' => ['Authorization' => 'Bearer secret', 'X-Keep' => '1']]);

        self::assertSame(['X-Keep' => '1'], $calls[1]['options']['headers']);
    }

    public function test_query_strings_are_applied_before_the_fragment(): void
    {
        $calls = [];
        $transport = $this->transport([['status' => 200]], $calls);
        $transport->send('GET', 'https://example.test/path?old=1#section', ['query' => 'new=2']);

        self::assertSame('https://example.test/path?old=1&new=2#section', $calls[0]['url']);
    }

    public function test_proxy_arrays_are_rejected_instead_of_silently_ignored(): void
    {
        $transport = new RnetTransport(null, static fn (array $config): object => new \stdClass());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('proxy arrays are not supported');
        $transport->send('GET', 'https://example.test/', ['proxy' => ['https' => 'http://proxy.test:8080']]);
    }

    public function test_redirect_protocol_allow_list_is_enforced_before_the_next_request(): void
    {
        $calls = [];
        $transport = $this->transport([
            ['status' => 302, 'headers' => ['location' => 'http://other.test/next']],
            ['status' => 200],
        ], $calls);

        $this->expectException(\HElturkey\WebTrawler\Exception\ScraperException::class);
        $transport->send('GET', 'https://example.test/start', ['allow_redirects' => ['protocols' => ['https']]]);
    }

    public function test_rnet_rejects_unsupported_redirect_tracking_and_custom_ca_paths(): void
    {
        $transport = new RnetTransport(null, static fn (array $config): object => new \stdClass());
        try {
            $transport->send('GET', 'https://example.test/', ['allow_redirects' => ['track_redirects' => true]]);
            self::fail('Rnet cannot silently ignore redirect history requests.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('track_redirects', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('boolean TLS verification only');
        $transport->send('GET', 'https://example.test/', ['verify' => '/tmp/custom-ca.pem']);
    }

    public function test_rnet_rejects_malformed_size_and_private_network_options(): void
    {
        $transport = new RnetTransport(null, static fn (array $config): object => new \stdClass());
        try {
            $transport->send('GET', 'https://example.test/', ['max_body_bytes' => '1024']);
            self::fail('String size limits must not disable response-size enforcement.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('max_body_bytes', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('block_private_networks must be a boolean');
        $transport->send('GET', 'https://example.test/', ['block_private_networks' => 'false']);
    }

    public function test_a_non_utf8_body_is_read_through_bytes(): void
    {
        $raw = iconv('UTF-8', 'WINDOWS-1256', 'مرحبا');
        $response = $this->transport([['status' => 200, 'bytes' => true, 'body' => $raw]])->send('GET', 'https://example.test/', []);

        self::assertSame($raw, $response->body);
    }

    public function test_oversized_content_length_is_rejected_before_the_body_is_read(): void
    {
        $transport = $this->transport([['status' => 200, 'headers' => ['content-length' => '5000'], 'body' => 'x']]);

        $this->expectException(ResponseTooLargeException::class);
        $transport->send('GET', 'https://example.test/', ['max_body_bytes' => 1000]);
    }

    public function test_extension_failures_become_domain_exceptions_without_leaking_urls(): void
    {
        if (RnetTransport::isAvailable()) {
            self::markTestSkipped('The real extension cannot be faked; its timeout path is covered by LocalServerIntegrationTest.');
        }
        eval('namespace RNet; class TimeoutException extends \\Exception {}');
        $rnetTimeout = new \RNet\TimeoutException('request error: error sending request for uri (https://u:p@example.test/?token=SECRET)');

        try {
            $this->transport([['status' => 200, 'throw' => $rnetTimeout]])->send('GET', 'https://example.test/', []);
            self::fail('Expected an exception.');
        } catch (TransientFailure $failure) {
            self::assertStringNotContainsString('SECRET', $failure->getMessage());
            self::assertSame($rnetTimeout, $failure->getPrevious());
        }
    }

    public function test_unsupported_options_fail_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RnetTransport(null, static fn (array $config): object => new \stdClass()))->send('POST', 'https://example.test/', ['multipart' => []]);
    }
}
