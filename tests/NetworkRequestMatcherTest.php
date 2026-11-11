<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Browser\NetworkRequestMatcher;
use PHPUnit\Framework\TestCase;

final class NetworkRequestMatcherTest extends TestCase
{
    public function test_it_matches_a_response_by_url_when_playwright_omits_request_metadata(): void
    {
        $requests = [[
            'method' => 'GET',
            'resource_type' => 'fetch',
            'status' => null,
        ]];

        self::assertSame(
            0,
            NetworkRequestMatcher::findPendingIndex(
                'https://example.test/api/books',
                null,
                null,
                $requests,
                ['https://example.test/api/books'],
            ),
        );
    }

    public function test_it_uses_method_and_resource_type_when_available(): void
    {
        $requests = [
            ['method' => 'GET', 'resource_type' => 'fetch', 'status' => null],
            ['method' => 'POST', 'resource_type' => 'xhr', 'status' => null],
        ];

        self::assertSame(
            1,
            NetworkRequestMatcher::findPendingIndex(
                'https://example.test/api/books',
                'POST',
                'xhr',
                $requests,
                [
                    'https://example.test/api/books',
                    'https://example.test/api/books',
                ],
            ),
        );
    }

    public function test_it_does_not_correlate_ambiguous_concurrent_identical_requests(): void
    {
        $requests = [
            ['method' => 'GET', 'resource_type' => 'fetch', 'status' => null],
            ['method' => 'GET', 'resource_type' => 'fetch', 'status' => null],
        ];
        $urls = [
            'https://example.test/api/books',
            'https://example.test/api/books',
        ];

        self::assertNull(NetworkRequestMatcher::findPendingIndex(
            'https://example.test/api/books',
            null,
            null,
            $requests,
            $urls,
        ));
    }

    public function test_it_skips_completed_and_failed_requests(): void
    {
        $requests = [
            ['method' => 'GET', 'resource_type' => 'fetch', 'status' => 200],
            ['method' => 'GET', 'resource_type' => 'fetch', 'status' => null, 'failure' => 'network error'],
            ['method' => 'GET', 'resource_type' => 'fetch', 'status' => null],
        ];
        $urls = array_fill(0, 3, 'https://example.test/api/books');

        self::assertSame(
            2,
            NetworkRequestMatcher::findPendingIndex(
                'https://example.test/api/books',
                'GET',
                'fetch',
                $requests,
                $urls,
            ),
        );
    }
}
