<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Transport;

use HElturkey\WebTrawler\Exception\HostNotAllowedException;
use HElturkey\WebTrawler\Exception\ResponseTooLargeException;
use HElturkey\WebTrawler\Transport\Drivers\AmpTransport;
use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Tests\Support\LocalServer;
use PHPUnit\Framework\TestCase;

/** Real HTTP through Amp and Revolt; skipped unless amphp/http-client is installed. */
final class AmpTransportTest extends TestCase
{
    private static ?LocalServer $server = null;

    public static function setUpBeforeClass(): void
    {
        if (class_exists(\Amp\Http\Client\HttpClientBuilder::class)) {
            self::$server = LocalServer::start();
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (!class_exists(\Amp\Http\Client\HttpClientBuilder::class)) {
            self::markTestSkipped('amphp/http-client is not installed.');
        }
        if (self::$server === null) {
            self::markTestSkipped('The local test server could not be started.');
        }
    }

    private function url(string $path): string
    {
        return (self::$server?->baseUrl ?? '') . $path;
    }

    private function scraper(): Scraper
    {
        return Scraper::create(new AmpTransport(), defaultOptions: new FetchOptions(blockPrivateNetworks: false, timeout: 10));
    }

    public function test_it_fetches_and_follows_redirects(): void
    {
        $page = $this->scraper()->fetch($this->url('/redir'));

        self::assertSame('hi', $page->css('h1')->first()?->text());
        self::assertSame($this->url('/small'), $page->url());
    }

    public function test_it_sends_a_form_post(): void
    {
        $page = $this->scraper()->post($this->url('/echo'), ['a' => '1', 'b' => 'x y']);
        $echo = json_decode($page->response()?->body ?? '', true);

        self::assertIsArray($echo);
        self::assertSame('POST', $echo['method']);
        self::assertSame('a=1&b=x+y', $echo['body']);
    }

    public function test_downloads_overlap_instead_of_running_one_after_another(): void
    {
        $spider = new class ($this->url('/delay?ms=400&n=')) extends Spider {
            public function __construct(private readonly string $base)
            {
            }

            public function startRequests(): iterable
            {
                foreach (range(1, 4) as $n) {
                    yield SpiderRequest::get($this->base . $n);
                }
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield ['n' => $page->text()];
            }
        };
        $spider->configure(['concurrency' => 4]);

        $started = hrtime(true);
        $result = $this->scraper()->crawl($spider);
        $seconds = (hrtime(true) - $started) / 1e9;

        self::assertCount(4, $result->items);
        self::assertSame([], $result->errors);
        self::assertLessThan(1.4, $seconds, 'Four 400 ms requests in parallel must take well under their 1.6 s sum.');
    }

    public function test_the_body_limit_is_enforced_for_announced_and_streamed_bodies(): void
    {
        $scraper = Scraper::create(new AmpTransport(), defaultOptions: new FetchOptions(blockPrivateNetworks: false, maxBodyBytes: 100_000));

        foreach (['/biglen', '/big'] as $path) {
            try {
                $scraper->fetch($this->url($path));
                self::fail("$path must exceed the limit.");
            } catch (ResponseTooLargeException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_every_redirect_hop_is_checked_against_the_policy(): void
    {
        $scraper = Scraper::create(new AmpTransport(), defaultOptions: new FetchOptions(blockPrivateNetworks: false, allowedHosts: ['127.0.0.1']));

        $this->expectException(HostNotAllowedException::class);
        $scraper->fetch($this->url('/redir-other-host'));
    }

    public function test_a_redirect_loop_stops(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->scraper()->fetch($this->url('/redir-loop'));
    }

    public function test_timeouts_are_reported_as_retryable_network_failures(): void
    {
        $scraper = Scraper::create(new AmpTransport(), defaultOptions: new FetchOptions(blockPrivateNetworks: false, timeout: 0.5));

        $this->expectException(\HElturkey\WebTrawler\Contracts\TransientFailure::class);
        $scraper->fetch($this->url('/sleep'));
    }


    public function test_a_custom_client_factory_must_explicitly_declare_proxy_support(): void
    {
        $factory = static fn (bool $verifyTls, ?string $proxy): \Amp\Http\Client\HttpClient => throw new \LogicException('Factory is not invoked by this test.');
        $withoutProxySupport = new AmpTransport(clientFactory: $factory);
        $withProxySupport = new AmpTransport(clientFactory: $factory, clientFactorySupportsProxy: true);

        self::assertFalse($withoutProxySupport->supportsProxy());
        self::assertTrue($withProxySupport->supportsProxy());
    }

    public function test_the_proxy_option_is_refused_with_a_pointer_to_the_alternative(): void
    {
        if ((new AmpTransport())->supportsProxy()) {
            self::markTestSkipped('amphp/http-tunnel is installed, so proxies are supported.');
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('amphp/http-tunnel');
        (new AmpTransport())->send('GET', $this->url('/small'), ['proxy' => 'http://127.0.0.1:9']);
    }
}
