<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Browser\NetworkMetadata;
use PHPUnit\Framework\TestCase;

final class NetworkMetadataTest extends TestCase
{
    public function test_it_redacts_sensitive_request_and_response_headers(): void
    {
        $headers = NetworkMetadata::sanitizeHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer secret-token',
            'Cookie' => 'session=private',
            'X-Api-Key' => 'private-key',
        ]);

        self::assertSame('application/json', $headers['Accept']);
        self::assertSame('[REDACTED]', $headers['Authorization']);
        self::assertSame('[REDACTED]', $headers['Cookie']);
        self::assertSame('[REDACTED]', $headers['X-Api-Key']);
    }

    public function test_it_redacts_credentials_and_sensitive_url_parameters(): void
    {
        $url = NetworkMetadata::sanitizeUrl('https://user:pass@example.test/api?sort=recent&access_token=secret&key=private#section');

        self::assertSame('https://example.test/api?sort=recent&access_token=[REDACTED]&key=[REDACTED]#section', $url);
    }

    public function test_it_truncates_payloads_by_bytes(): void
    {
        self::assertSame('abc', NetworkMetadata::truncate('abcdef', 3));
        self::assertSame('abcdef', NetworkMetadata::truncate('abcdef', 9));
    }
}
