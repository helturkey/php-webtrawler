<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Transport;

use HElturkey\WebTrawler\Fetching\Fetcher;
use HElturkey\WebTrawler\Cli\Application;
use HElturkey\WebTrawler\Contracts\Http\TransportInterface;
use HElturkey\WebTrawler\Transport\Drivers\BrowserTransport;
use HElturkey\WebTrawler\Response\FetchResponse;
use HElturkey\WebTrawler\Transport\TransportRegistry;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Tests\Support\FixedBrowserDriver;
use PHPUnit\Framework\TestCase;

/**
 * Proves the extension points: everything here is code a separate package could ship, using only the public
 * contracts (TransportInterface, BrowserDriver, TransportRegistry) and no change to Fetcher or SpiderRunner.
 */
final class TransportExtensionTest extends TestCase
{
    private function blocking(string $html = '<p>custom</p>'): TransportInterface
    {
        return new class ($html) implements TransportInterface {
            public int $calls = 0;

            public function __construct(private readonly string $html)
            {
            }

            public function send(string $method, string $url, array $options = []): FetchResponse
            {
                ++$this->calls;

                return new FetchResponse(200, ['Content-Type' => ['text/html; charset=utf-8']], $this->html, $url, $url, 1.0);
            }
        };
    }

    public function test_a_plain_http_transport_is_enough_for_fetching_and_for_a_sequential_spider(): void
    {
        $transport = $this->blocking();
        $scraper = Scraper::create($transport);

        self::assertSame('custom', $scraper->fetch('https://example.test/')->css('p')->first()?->text());

        $spider = new class () extends Spider {
            protected array $startUrls = ['https://example.test/one'];

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield ['text' => $page->text()];
            }
        };
        self::assertSame([['text' => 'custom']], $scraper->crawl($spider)->items);
    }

    public function test_a_browser_driver_works_as_a_transport_without_touching_the_fetcher(): void
    {
        $driver = new FixedBrowserDriver();
        $page = Scraper::create(new BrowserTransport($driver, ['wait_until' => 'networkidle']))->fetch('https://example.test/app');

        self::assertSame('مرحبا', $page->css('h1')->first()?->text(), 'Browser output is decoded text; the windows-1256 header must be ignored.');
        self::assertCount(1, $page->apiResponses());
        self::assertSame('networkidle', $driver->calls[0]['options']['wait_until']);
        self::assertSame(30000.0, $driver->calls[0]['options']['timeout'], 'Fetch timeouts (seconds) become browser timeouts (milliseconds).');
    }

    public function test_browser_transports_refuse_non_get_requests_instead_of_downgrading_them(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BrowserTransport(new FixedBrowserDriver()))->send('POST', 'https://example.test/', []);
    }

    public function test_fetch_rendered_accepts_any_browser_driver(): void
    {
        $page = Scraper::create($this->blocking())->fetchRendered('https://example.test/app', new FixedBrowserDriver());

        self::assertSame('مرحبا', $page->css('h1')->first()?->text());
        self::assertSame('https://example.test/app', $page->url());
    }

    public function test_the_package_enforces_url_policy_for_untrusted_browser_drivers(): void
    {
        $driver = new FixedBrowserDriver();
        $scraper = Scraper::create($this->blocking(), defaultOptions: new \HElturkey\WebTrawler\Options\FetchOptions(allowedHosts: ['example.test']), enforceDefaultSecurity: true);

        try {
            $scraper->fetchRendered('https://evil.test/', $driver);
            self::fail('A disallowed host must be refused before the driver is called.');
        } catch (\HElturkey\WebTrawler\Exception\HostNotAllowedException) {
            self::assertSame([], $driver->calls, 'The custom driver never saw the URL.');
        }

        $private = Scraper::create($this->blocking(), defaultOptions: new \HElturkey\WebTrawler\Options\FetchOptions(blockPrivateNetworks: true), enforceDefaultSecurity: true);
        $this->expectException(\HElturkey\WebTrawler\Exception\UnsafeUrlException::class);
        $private->fetchRendered('http://127.0.0.1/admin', $driver);
    }

    public function test_the_final_browser_url_is_checked_after_navigation(): void
    {
        $redirecting = new class () implements \HElturkey\WebTrawler\Contracts\BrowserDriver {
            public function render(string $url, array $options = []): \HElturkey\WebTrawler\Browser\BrowserResult
            {
                return new \HElturkey\WebTrawler\Browser\BrowserResult('<p>x</p>', $url, 'https://elsewhere.test/landing', statusCode: 200);
            }
        };
        $scraper = Scraper::create($this->blocking(), defaultOptions: new \HElturkey\WebTrawler\Options\FetchOptions(allowedHosts: ['example.test']), enforceDefaultSecurity: true);

        $this->expectException(\HElturkey\WebTrawler\Exception\HostNotAllowedException::class);
        $scraper->fetchRendered('https://example.test/start', $redirecting);
    }


    public function test_an_untrusted_browser_driver_cannot_return_a_non_http_final_url(): void
    {
        $driver = new class () implements \HElturkey\WebTrawler\Contracts\BrowserDriver {
            public function render(string $url, array $options = []): \HElturkey\WebTrawler\Browser\BrowserResult
            {
                return new \HElturkey\WebTrawler\Browser\BrowserResult('<p>x</p>', $url, 'file:///etc/passwd', statusCode: 200);
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        Scraper::create($this->blocking())->fetchRendered('https://example.test/', $driver);
    }

    public function test_a_proxy_pool_cannot_wrap_an_amp_transport_that_has_no_proxy_support(): void
    {
        if (!class_exists(\Amp\Http\Client\HttpClientBuilder::class)) {
            self::markTestSkipped('amphp/http-client is not installed.');
        }
        $amp = new \HElturkey\WebTrawler\Transport\Drivers\AmpTransport();
        if ($amp->supportsProxy()) {
            self::markTestSkipped('amphp/http-tunnel is installed.');
        }

        $this->expectException(\LogicException::class);
        new \HElturkey\WebTrawler\Proxy\ProxyTransport($amp, new \HElturkey\WebTrawler\Proxy\ProxyPool(['http://proxy.test:8080']));
    }

    public function test_the_registry_resolves_default_and_custom_transports(): void
    {
        $registry = TransportRegistry::withDefaults();
        $custom = $this->blocking('<p>registered</p>');
        $registry->register('patchright', static fn (array $config): TransportInterface => $custom);

        self::assertTrue($registry->has('guzzle'));
        self::assertTrue($registry->has('amp'));
        self::assertContains('patchright', $registry->names());
        self::assertSame($custom, $registry->create('patchright', ['anything' => true]));
    }

    public function test_registry_rejects_unknown_names_and_malformed_registrations(): void
    {
        $registry = TransportRegistry::withDefaults();

        try {
            $registry->create('nope');
            self::fail('Unknown transports must fail loudly.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('guzzle', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $registry->register('Bad Name', fn (array $config): TransportInterface => $this->blocking());
    }

    public function test_the_cli_selects_a_registered_transport_by_name(): void
    {
        $registry = TransportRegistry::withDefaults();
        $registry->register('fake', fn (array $config): TransportInterface => $this->blocking('<p>from the cli</p>'));

        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = (new Application($out, $err, null, null, $registry))->run(['fetch', 'https://example.test/', '--transport', 'fake', '--select', 'p', '--format', 'text']);
        rewind($out);
        rewind($err);

        self::assertSame(0, $code, (string) stream_get_contents($err));
        self::assertStringContainsString('from the cli', (string) stream_get_contents($out));
    }

    public function test_the_cli_lists_the_available_transports_for_an_unknown_name(): void
    {
        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = (new Application($out, $err))->run(['fetch', 'https://example.test/', '--transport', 'warp-drive']);
        rewind($err);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('guzzle', (string) stream_get_contents($err));
    }
}
