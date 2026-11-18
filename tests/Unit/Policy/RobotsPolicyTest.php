<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Policy;

use HElturkey\WebTrawler\Exception\NetworkException;
use HElturkey\WebTrawler\Exception\UnsafeUrlException;
use HElturkey\WebTrawler\Policy\RobotsPolicy;
use HElturkey\WebTrawler\Response\FetchResponse;
use PHPUnit\Framework\TestCase;

final class RobotsPolicyTest extends TestCase
{
    private int $now = 1_000;

    /** @var list<string> */
    private array $fetched = [];

    /** @param callable(string): FetchResponse $answer */
    private function policy(callable $answer, int $ttl = 100, int $failureTtl = 10): RobotsPolicy
    {
        return new RobotsPolicy(
            function (string $url) use ($answer): FetchResponse {
                $this->fetched[] = $url;

                return $answer($url);
            },
            'TestBot/1.0',
            $ttl,
            $failureTtl,
            fn (): int => $this->now,
        );
    }

    private static function response(int $status, string $body = ''): FetchResponse
    {
        return new FetchResponse($status, [], $body, 'https://a.test/robots.txt', 'https://a.test/robots.txt', 1.0);
    }

    public function test_it_fetches_once_per_origin_and_caches_until_the_ttl_expires(): void
    {
        $policy = $this->policy(fn (string $url): FetchResponse => str_contains($url, 'a.test')
            ? self::response(200, "User-agent: *\nDisallow: /no\nCrawl-delay: 2\n")
            : self::response(404));

        self::assertTrue($policy->isAllowed('https://a.test/yes'));
        self::assertFalse($policy->isAllowed('https://a.test/no/page'));
        self::assertSame(2_000, $policy->crawlDelayMs('https://a.test/yes'));
        self::assertSame(['https://a.test/robots.txt'], $this->fetched);

        self::assertTrue($policy->isAllowed('https://b.test/no/page'), 'Another origin has its own rules.');
        self::assertSame(['https://a.test/robots.txt', 'https://b.test/robots.txt'], $this->fetched);

        $this->now += 101;
        $policy->isAllowed('https://a.test/yes');
        self::assertCount(3, $this->fetched, 'The entry expired and was fetched again.');
    }

    public function test_the_port_and_scheme_are_part_of_the_origin(): void
    {
        $policy = $this->policy(fn (): FetchResponse => self::response(404));

        $policy->isAllowed('https://a.test/x');
        $policy->isAllowed('http://a.test/x');
        $policy->isAllowed('https://a.test:8443/x');

        self::assertSame(['https://a.test/robots.txt', 'http://a.test/robots.txt', 'https://a.test:8443/robots.txt'], $this->fetched);
    }

    public function test_a_missing_robots_file_allows_everything(): void
    {
        $policy = $this->policy(fn (): FetchResponse => self::response(404));

        self::assertTrue($policy->isAllowed('https://a.test/anything'));
        self::assertSame(0, $policy->crawlDelayMs('https://a.test/anything'));
    }

    public function test_a_server_error_disallows_everything_but_recovers_after_the_short_failure_ttl(): void
    {
        $status = 503;
        $policy = $this->policy(function () use (&$status): FetchResponse {
            return self::response($status, "User-agent: *\nDisallow:\n");
        });

        self::assertFalse($policy->isAllowed('https://a.test/x'));
        $this->now += 5;
        self::assertFalse($policy->isAllowed('https://a.test/x'));
        self::assertCount(1, $this->fetched);

        $status = 200;
        $this->now += 6;
        self::assertTrue($policy->isAllowed('https://a.test/x'));
        self::assertCount(2, $this->fetched);
    }

    public function test_an_unreachable_server_disallows_everything(): void
    {
        $policy = $this->policy(static function (): FetchResponse {
            throw new NetworkException('connection refused');
        });

        self::assertFalse($policy->isAllowed('https://a.test/x'));
    }

    public function test_url_policy_violations_are_not_hidden_behind_a_disallow(): void
    {
        $policy = $this->policy(static function (): FetchResponse {
            throw new UnsafeUrlException('private address');
        });

        $this->expectException(UnsafeUrlException::class);
        $policy->isAllowed('https://a.test/x');
    }

    public function test_declared_sitemaps_are_exposed(): void
    {
        $policy = $this->policy(fn (): FetchResponse => self::response(200, "User-agent: *\nSitemap: https://a.test/s.xml\n"));

        self::assertSame(['https://a.test/s.xml'], $policy->sitemaps('https://a.test/page'));
    }
}
