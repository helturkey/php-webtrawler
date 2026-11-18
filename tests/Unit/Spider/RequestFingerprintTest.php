<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Spider;

use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Spider\RequestFingerprint;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use PHPUnit\Framework\TestCase;

final class RequestFingerprintTest extends TestCase
{
    public function test_equivalent_urls_share_a_fingerprint(): void
    {
        $first = RequestFingerprint::for(SpiderRequest::get('HTTP://Example.TEST:80/path#section'));
        $second = RequestFingerprint::for(SpiderRequest::get('http://example.test/path'));

        self::assertSame($first, $second);
    }

    public function test_query_order_is_preserved_because_it_can_change_request_semantics(): void
    {
        $first = RequestFingerprint::for(SpiderRequest::get('https://example.test/search?a=1&b=2'));
        $second = RequestFingerprint::for(SpiderRequest::get('https://example.test/search?b=2&a=1'));

        self::assertNotSame($first, $second);
    }

    public function test_different_methods_and_post_payloads_are_distinct(): void
    {
        $get = RequestFingerprint::for(SpiderRequest::get('https://example.test/form'));
        $postOne = RequestFingerprint::for(SpiderRequest::post('https://example.test/form', ['name' => 'one']));
        $postTwo = RequestFingerprint::for(SpiderRequest::post('https://example.test/form', ['name' => 'two']));

        self::assertNotSame($get, $postOne);
        self::assertNotSame($postOne, $postTwo);
    }

    public function test_malformed_urls_still_keep_distinct_payloads(): void
    {
        $first = RequestFingerprint::for(SpiderRequest::post('not-a-url', ['value' => 'one']));
        $second = RequestFingerprint::for(SpiderRequest::post('not-a-url', ['value' => 'two']));

        self::assertNotSame($first, $second);
    }

    public function test_request_headers_and_options_are_part_of_the_identity(): void
    {
        $english = RequestFingerprint::for(new SpiderRequest(
            'https://example.test/page',
            options: new FetchOptions(headers: ['Accept-Language' => 'en']),
        ));
        $arabic = RequestFingerprint::for(new SpiderRequest(
            'https://example.test/page',
            options: new FetchOptions(headers: ['Accept-Language' => 'ar']),
        ));

        self::assertNotSame($english, $arabic);
    }

    public function test_associative_payload_key_order_is_preserved_to_avoid_conflating_requests(): void
    {
        $first = RequestFingerprint::for(SpiderRequest::post('https://example.test/form', ['first' => 1, 'second' => 2]));
        $second = RequestFingerprint::for(SpiderRequest::post('https://example.test/form', ['second' => 2, 'first' => 1]));

        self::assertNotSame($first, $second);
    }
}
