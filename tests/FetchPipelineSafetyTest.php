<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Contracts\HttpTransport;
use HElturkey\WebTrawler\Exception\ResponseTooLargeException;
use HElturkey\WebTrawler\Exception\UnsafeUrlException;
use HElturkey\WebTrawler\Http\FetchOptions;
use HElturkey\WebTrawler\Http\FetchResponse;
use HElturkey\WebTrawler\Scraper;
use PHPUnit\Framework\TestCase;

final class FetchPipelineSafetyTest extends TestCase
{
    private function transport(string $body = '<p>ok</p>'): HttpTransport
    {
        return new class ($body) implements HttpTransport {
            public ?string $lastUrl = null;
            /** @var array<string, mixed> */
            public array $lastOptions = [];

            public function __construct(private string $body)
            {
            }

            public function send(string $method, string $url, array $options = []): FetchResponse
            {
                $this->lastUrl = $url;
                $this->lastOptions = $options;

                return new FetchResponse(200, [], $this->body, $url, $url, 1.0);
            }
        };
    }

    public function test_unicode_urls_are_normalized_before_reaching_the_transport(): void
    {
        $transport = $this->transport();
        Scraper::create($transport)->get('https://example.test/كتاب ما');

        self::assertSame('https://example.test/%D9%83%D8%AA%D8%A7%D8%A8%20%D9%85%D8%A7', $transport->lastUrl);
    }

    public function test_oversized_bodies_are_rejected_even_by_transports_that_cannot_stream_limits(): void
    {
        $this->expectException(ResponseTooLargeException::class);
        Scraper::create($this->transport(str_repeat('x', 200)))->get('https://example.test/', new FetchOptions(maxBodyBytes: 100));
    }

    public function test_private_targets_are_refused_before_any_request_when_enabled(): void
    {
        $transport = $this->transport();

        try {
            Scraper::create($transport)->get('http://169.254.169.254/', new FetchOptions(blockPrivateNetworks: true));
            self::fail('Expected UnsafeUrlException.');
        } catch (UnsafeUrlException) {
            self::assertNull($transport->lastUrl);
        }
    }

    public function test_extra_headers_merge_beneath_explicit_headers_and_limits_reach_the_transport(): void
    {
        $transport = $this->transport();
        $options = new FetchOptions(
            headers: ['X-Explicit' => 'a', 'X-Both' => 'explicit'],
            extra: ['headers' => ['X-Extra' => 'b', 'X-Both' => 'extra']],
        );
        Scraper::create($transport)->get('https://example.test/', $options);

        self::assertSame(['X-Extra' => 'b', 'X-Both' => 'explicit', 'X-Explicit' => 'a'], $transport->lastOptions['headers']);
        self::assertSame(FetchOptions::DEFAULT_MAX_BODY_BYTES, $transport->lastOptions['max_body_bytes']);
    }

    public function test_with_extra_option_does_not_mutate_the_original(): void
    {
        $original = new FetchOptions(timeout: 5.0);
        $copy = $original->withExtraOption('form_params', ['a' => 1]);

        self::assertSame([], $original->extra);
        self::assertSame(['form_params' => ['a' => 1]], $copy->extra);
        self::assertSame(5.0, $copy->timeout);
    }

    public function test_invalid_options_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FetchOptions(maxBodyBytes: 0);
    }
}
