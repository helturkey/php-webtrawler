<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use HElturkey\WebTrawler\Cli\Application;
use HElturkey\WebTrawler\Exception\HostNotAllowedException;
use HElturkey\WebTrawler\Exception\HttpStatusException;
use HElturkey\WebTrawler\Exception\ResponseTooLargeException;
use HElturkey\WebTrawler\Http\FetchOptions;
use HElturkey\WebTrawler\Http\RetryPolicy;
use HElturkey\WebTrawler\Http\FetchResponse;
use HElturkey\WebTrawler\Exception\NetworkException;
use HElturkey\WebTrawler\Contracts\HttpTransport;
use HElturkey\WebTrawler\Http\Fetcher;
use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Http\GuzzleTransport;
use HElturkey\WebTrawler\Http\HostPolicy;
use HElturkey\WebTrawler\Http\RnetTransport;
use HElturkey\WebTrawler\Mcp\McpServer;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Support\UrlSanitizer;
use HElturkey\WebTrawler\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

final class PolicyEnforcementTest extends TestCase
{
    // ---- tighten-only policy -------------------------------------------------------------------

    public function test_checkpointing_rejects_explicit_proxy_urls_instead_of_resuming_direct(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('explicit request proxy is not persisted');
        (new FetchOptions(proxy: 'http://user:secret@proxy.example.test:8080'))->toState();
    }

    public function test_allowed_hosts_must_be_a_list_of_strings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FetchOptions(allowedHosts: ['primary' => 'example.test']);
    }

    public function test_fetch_options_reject_non_finite_timeouts(): void
    {
        foreach ([INF, -INF, NAN] as $timeout) {
            try {
                new FetchOptions(timeout: $timeout);
                self::fail('Non-finite timeout values must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('finite', $exception->getMessage());
            }
        }

        foreach ([INF, NAN] as $timeout) {
            try {
                new FetchOptions(connectTimeout: $timeout);
                self::fail('Non-finite connect timeouts must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('finite', $exception->getMessage());
            }
        }
    }

    public function test_a_policy_can_only_make_options_stricter(): void
    {
        $policy = new FetchOptions(maxBodyBytes: 1000, blockPrivateNetworks: true, allowedHosts: ['example.test']);

        $loose = (new FetchOptions(maxBodyBytes: null, blockPrivateNetworks: false))->tightenedBy($policy);
        self::assertTrue($loose->blockPrivateNetworks);
        self::assertSame(1000, $loose->maxBodyBytes);
        self::assertSame(['example.test'], $loose->allowedHosts);

        $larger = (new FetchOptions(maxBodyBytes: 5_000_000))->tightenedBy($policy);
        self::assertSame(1000, $larger->maxBodyBytes, 'The smaller limit wins.');
        $smaller = (new FetchOptions(maxBodyBytes: 10))->tightenedBy($policy);
        self::assertSame(10, $smaller->maxBodyBytes);

        $narrowed = (new FetchOptions(allowedHosts: ['api.example.test', 'evil.test']))->tightenedBy($policy);
        self::assertSame(['api.example.test'], $narrowed->allowedHosts, 'Hosts outside the policy are dropped.');
        try {
            (new FetchOptions(allowedHosts: ['evil.test']))->tightenedBy($policy);
            self::fail('Disjoint allow-lists must fail closed rather than replacing the request restriction.');
        } catch (HostNotAllowedException $exception) {
            self::assertStringContainsString('does not intersect', $exception->getMessage());
        }

        $narrowPolicy = (new FetchOptions(allowedHosts: ['example.test']))->tightenedBy(
            new FetchOptions(maxBodyBytes: null, allowedHosts: ['api.example.test']),
        );
        self::assertSame(['api.example.test'], $narrowPolicy->allowedHosts, 'A narrower policy host wins over a broader request host.');

        $unrestricted = (new FetchOptions(timeout: 4.0))->tightenedBy(new FetchOptions(maxBodyBytes: null));
        self::assertSame(4.0, $unrestricted->timeout);
        self::assertSame(FetchOptions::DEFAULT_MAX_BODY_BYTES, $unrestricted->maxBodyBytes, 'A policy without a limit leaves the request limit alone.');

        $tightTimeouts = (new FetchOptions(timeout: 90, connectTimeout: 45, verifyTls: false, allowRedirects: true, maxRedirects: 8))
            ->tightenedBy(new FetchOptions(timeout: 12, connectTimeout: 4, verifyTls: true, allowRedirects: false, maxRedirects: 3));
        self::assertSame(12.0, $tightTimeouts->timeout);
        self::assertSame(4.0, $tightTimeouts->connectTimeout);
        self::assertTrue($tightTimeouts->verifyTls, 'A request cannot disable application TLS verification.');
        self::assertFalse($tightTimeouts->allowRedirects, 'A request cannot enable redirects disabled by policy.');
        self::assertSame(3, $tightTimeouts->maxRedirects);

        $unlimitedTimeout = (new FetchOptions(timeout: 0))->tightenedBy(new FetchOptions(timeout: 8));
        self::assertSame(8.0, $unlimitedTimeout->timeout, 'Zero means unlimited, so a finite policy timeout wins.');
        $unlimitedPolicy = (new FetchOptions(timeout: 8))->tightenedBy(new FetchOptions(timeout: 0));
        self::assertSame(8.0, $unlimitedPolicy->timeout, 'An unlimited policy does not loosen a finite request timeout.');
    }

    public function test_an_enforced_default_proxy_cannot_be_replaced_by_a_request(): void
    {
        $policy = new FetchOptions(proxy: 'http://proxy.example.test:8080');
        $same = (new FetchOptions())->tightenedBy($policy);
        self::assertSame('http://proxy.example.test:8080', $same->proxy);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot replace the proxy route');
        (new FetchOptions(proxy: 'http://other-proxy.example.test:8080'))->tightenedBy($policy);
    }

    public function test_browser_options_inherit_and_tighten_scraper_security_defaults(): void
    {
        $defaults = new FetchOptions(
            timeout: 12,
            proxy: 'http://proxy.example.test:8080',
            maxBodyBytes: 1000,
            blockPrivateNetworks: true,
            allowedHosts: ['example.test'],
        );
        $fetcher = new Fetcher(new RecordingTransport(), new InMemorySnapshotStore(), new AdaptiveMatcher(), $defaults, true);

        $inherited = $fetcher->browserOptions([]);
        self::assertSame(12_000.0, $inherited['timeout']);
        self::assertSame('http://proxy.example.test:8080', $inherited['proxy']);
        self::assertSame(1000, $inherited['max_body_bytes']);
        self::assertTrue($inherited['block_private_networks']);
        self::assertSame(['example.test'], $inherited['allowed_hosts']);

        $narrowed = $fetcher->browserOptions([
            'timeout' => 5000,
            'max_body_bytes' => 5000,
            'block_private_networks' => false,
            'allowed_hosts' => ['api.example.test', 'evil.test'],
        ]);
        self::assertSame(5000.0, $narrowed['timeout']);
        self::assertSame(1000, $narrowed['max_body_bytes']);
        self::assertTrue($narrowed['block_private_networks']);
        self::assertSame(['api.example.test'], $narrowed['allowed_hosts']);
        self::assertSame('http://proxy.example.test:8080', $narrowed['proxy']);
    }

    public function test_browser_timeout_is_compared_in_seconds_then_returned_in_milliseconds(): void
    {
        $defaults = new FetchOptions(timeout: 12, connectTimeout: 5);
        $fetcher = new Fetcher(new RecordingTransport(), new InMemorySnapshotStore(), new AdaptiveMatcher(), $defaults, true);

        self::assertSame(12_000.0, $fetcher->browserOptions([])['timeout']);
        self::assertSame(12_000.0, $fetcher->browserOptions(['timeout' => 90_000])['timeout']);
        self::assertSame(5_000.0, $fetcher->browserOptions(['timeout' => 5_000])['timeout']);
        self::assertSame(12_000.0, $fetcher->browserOptions(['timeout' => 0])['timeout']);
    }

    public function test_fetcher_applies_default_retries_to_one_off_fetches(): void
    {
        $transport = new class () implements HttpTransport {
            public int $attempts = 0;

            public function send(string $method, string $url, array $options = []): FetchResponse
            {
                $this->attempts++;
                throw new NetworkException('Temporary network failure.');
            }
        };
        $fetcher = new Fetcher(
            $transport,
            new InMemorySnapshotStore(),
            new AdaptiveMatcher(),
            defaultRetryPolicy: new RetryPolicy(maxAttempts: 3, baseDelayMs: 0, jitter: RetryPolicy::JITTER_NONE),
        );

        try {
            $fetcher->fetch('https://example.test/');
            self::fail('The transport should continue to fail.');
        } catch (NetworkException) {
            self::assertSame(3, $transport->attempts);
        }
    }

    public function test_default_retry_policy_does_not_wrap_or_disable_the_async_transport(): void
    {
        $fetcher = new Fetcher(
            new GuzzleTransport(),
            new InMemorySnapshotStore(),
            new AdaptiveMatcher(),
            defaultRetryPolicy: new RetryPolicy(maxAttempts: 3, baseDelayMs: 0),
        );

        self::assertTrue($fetcher->supportsAsync());
    }

    public function test_browser_security_defaults_preserve_tls_verification(): void
    {
        $defaults = new FetchOptions(verifyTls: true);
        $fetcher = new Fetcher(new RecordingTransport(), new InMemorySnapshotStore(), new AdaptiveMatcher(), $defaults, true);

        self::assertTrue($fetcher->browserOptions(['verify_tls' => false])['verify_tls']);
        self::assertTrue($fetcher->browserOptions([])['verify_tls']);
    }

    public function test_browser_private_network_policy_rejects_non_boolean_values(): void
    {
        $fetcher = new Fetcher(new RecordingTransport(), new InMemorySnapshotStore(), new AdaptiveMatcher(), new FetchOptions(), true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a boolean');
        $fetcher->browserOptions(['block_private_networks' => 'false']);
    }

    public function test_browser_driver_fails_closed_when_the_policy_disables_redirects(): void
    {
        $defaults = new FetchOptions(allowRedirects: false);
        $fetcher = new Fetcher(new RecordingTransport(), new InMemorySnapshotStore(), new AdaptiveMatcher(), $defaults, true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('redirects internally');
        $fetcher->browserOptions([]);
    }

    public function test_host_policy_matches_exact_hosts_and_subdomains_only(): void
    {
        $allowed = ['example.test'];
        self::assertTrue(HostPolicy::allows('https://example.test/x', $allowed));
        self::assertTrue(HostPolicy::allows('https://API.Example.test./x', $allowed));
        self::assertFalse(HostPolicy::allows('https://evilexample.test/', $allowed), 'A suffix must be a whole label.');
        self::assertFalse(HostPolicy::allows('https://example.test.evil.test/', $allowed));
        self::assertFalse(HostPolicy::allows('/relative', $allowed));
        self::assertTrue(HostPolicy::allows('https://anything.test/', []));
    }

    // ---- SpiderRequest::post() must not shed the scraper's security policy ----------------------

    private function postSpider(string $url, ?FetchOptions $options = null): Spider
    {
        return new class ($url, $options) extends Spider {
            public function __construct(private string $url, private ?FetchOptions $options)
            {
            }

            public function startRequests(): iterable
            {
                yield SpiderRequest::post($this->url, ['q' => 'x', 'n' => 1], $this->options);
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield ['url' => $page->url()];
            }
        };
    }

    public function test_a_spider_post_inherits_the_scrapers_private_network_policy(): void
    {
        $transport = new RecordingTransport();
        $scraper = Scraper::create($transport, new InMemorySnapshotStore(), null, new FetchOptions(blockPrivateNetworks: true));

        $result = $scraper->crawl($this->postSpider('http://127.0.0.1:8080/admin'));

        self::assertSame([], $transport->calls, 'The private target must never be contacted.');
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('non-public', $result->errors[0]['message']);
    }

    public function test_a_spider_post_keeps_its_form_body_and_still_carries_the_defaults(): void
    {
        $transport = new RecordingTransport();
        $defaults = new FetchOptions(timeout: 7.0, blockPrivateNetworks: true, headers: ['X-Default' => '1']);
        $scraper = Scraper::create($transport, new InMemorySnapshotStore(), null, $defaults);

        $scraper->crawl($this->postSpider('https://8.8.8.8/submit'));

        self::assertCount(1, $transport->calls);
        $options = $transport->calls[0]['options'];
        self::assertSame('POST', $transport->calls[0]['method']);
        self::assertSame(['q' => 'x', 'n' => 1], $options['form_params']);
        self::assertTrue($options['block_private_networks']);
        self::assertSame(7.0, $options['timeout']);
        self::assertSame(['X-Default' => '1'], $options['headers']);
    }

    public function test_explicit_per_request_options_cannot_switch_off_an_enforced_policy(): void
    {
        $transport = new RecordingTransport();
        $policy = new FetchOptions(blockPrivateNetworks: true, maxBodyBytes: 500);
        $scraper = Scraper::create($transport, new InMemorySnapshotStore(), null, $policy, enforceDefaultSecurity: true);

        $result = $scraper->crawl($this->postSpider('http://10.0.0.5/x', new FetchOptions(blockPrivateNetworks: false, maxBodyBytes: null)));
        self::assertSame([], $transport->calls);
        self::assertStringContainsString('non-public', $result->errors[0]['message']);

        $scraper->crawl($this->postSpider('https://8.8.8.8/x', new FetchOptions(blockPrivateNetworks: false, maxBodyBytes: null)));
        self::assertSame(500, $transport->calls[0]['options']['max_body_bytes']);
        self::assertTrue($transport->calls[0]['options']['block_private_networks']);
    }

    public function test_fetch_options_reject_non_json_safe_extras_when_checkpointing(): void
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        try {
            $options = new FetchOptions(extra: ['body' => $stream]);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('extra option "body" is not JSON-safe');
            $options->toState();
        } finally {
            fclose($stream);
        }
    }

    // ---- allow-lists at the redirect boundary ---------------------------------------------------

    private function guzzle(MockHandler $mock): GuzzleTransport
    {
        return new GuzzleTransport(new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]));
    }

    public function test_a_redirect_to_a_host_outside_the_allow_list_is_refused_before_it_is_followed(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'https://other.test/landing']),
            new Response(200, [], '<p>secret from other.test</p>'),
        ]);
        $scraper = Scraper::create($this->guzzle($mock), new InMemorySnapshotStore());

        try {
            $scraper->get('https://start.example.test/', new FetchOptions(allowedHosts: ['example.test']));
            self::fail('The off-list redirect must be refused.');
        } catch (HostNotAllowedException $exception) {
            self::assertStringContainsString('allow-list', $exception->getMessage());
        }
        self::assertCount(1, $mock, 'The second response was never requested: the redirect was not followed.');
    }

    public function test_redirects_within_the_allow_list_still_work(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'https://www.example.test/next']),
            new Response(200, [], '<p>fine</p>'),
        ]);
        $page = Scraper::create($this->guzzle($mock), new InMemorySnapshotStore())->get('https://example.test/', new FetchOptions(allowedHosts: ['example.test']));

        self::assertSame('fine', $page->text());
    }

    public function test_a_spider_allow_list_is_enforced_at_the_transport_for_every_request(): void
    {
        $mock = new MockHandler([new Response(302, ['Location' => 'https://other.test/x']), new Response(200, [], '<p>leak</p>')]);
        $spider = new class () extends Spider {
            protected array $startUrls = ['https://example.test/a'];
            protected array $allowedHosts = ['example.test'];

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield ['t' => $page->text()];
            }
        };

        $result = Scraper::create($this->guzzle($mock), new InMemorySnapshotStore())->crawl($spider);

        self::assertSame([], $result->items);
        self::assertCount(1, $mock, 'other.test must never be contacted.');
        self::assertStringContainsString('allow-list', $result->errors[0]['message']);
    }

    public function test_a_request_level_allow_list_cannot_widen_the_spider_allow_list(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'https://evil.test/landing']),
            new Response(200, [], '<p>must not be reached</p>'),
        ]);
        $spider = new class () extends Spider {
            protected array $startUrls = ['https://example.test/start'];
            protected array $allowedHosts = ['example.test'];

            public function startRequests(): iterable
            {
                yield SpiderRequest::get(
                    'https://example.test/start',
                    new FetchOptions(allowedHosts: ['example.test', 'evil.test']),
                );
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield ['text' => $page->text()];
            }
        };

        $result = Scraper::create($this->guzzle($mock), new InMemorySnapshotStore())->crawl($spider);

        self::assertSame([], $result->items);
        self::assertCount(1, $mock, 'The request-level list must not permit the off-policy redirect.');
        self::assertStringContainsString('allow-list', $result->errors[0]['message']);
    }

    public function test_disjoint_request_and_spider_allow_lists_fail_before_network_access(): void
    {
        $transport = new RecordingTransport();
        $spider = new class () extends Spider {
            protected array $allowedHosts = ['example.test'];

            public function startRequests(): iterable
            {
                yield SpiderRequest::get('https://example.test/start', new FetchOptions(allowedHosts: ['evil.test']));
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                return [];
            }
        };

        $result = Scraper::create($transport, new InMemorySnapshotStore())->crawl($spider);

        self::assertSame([], $transport->calls);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('does not intersect', $result->errors[0]['message']);
    }

    public function test_the_fetcher_backstops_transports_that_cannot_check_redirect_hops(): void
    {
        $transport = new RecordingTransport([], ['https://example.test/a' => 'https://other.test/landed']);

        $this->expectException(HostNotAllowedException::class);
        Scraper::create($transport, new InMemorySnapshotStore())->get('https://example.test/a', new FetchOptions(allowedHosts: ['example.test']));
    }

    public function test_the_mcp_allow_list_covers_redirect_destinations(): void
    {
        $mock = new MockHandler([new Response(302, ['Location' => 'https://other.test/x']), new Response(200, [], '<p>leak</p>')]);
        $server = new McpServer(Scraper::create($this->guzzle($mock), new InMemorySnapshotStore()), ['allowed_hosts' => ['example.test'], 'block_private_networks' => false]);

        $response = json_decode((string) $server->handleMessage((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ],
                'name' => 'fetch_page',
                'arguments' => ['url' => 'https://example.test/start'],
            ],
        ])), true);

        self::assertTrue($response['result']['isError']);
        self::assertStringContainsString('allow-list', $response['result']['content'][0]['text']);
        self::assertCount(1, $mock);
    }

    public function test_the_rnet_adapter_checks_the_allow_list_on_every_hop(): void
    {
        $calls = 0;
        $transport = new RnetTransport(null, static function (array $config) use (&$calls): object {
            return new class ($calls) {
                public function __construct(private int &$calls)
                {
                }

                public function request(string $method, string $url, ?array $options): object
                {
                    $this->calls++;

                    return new class () {
                        public function status(): int
                        {
                            return 302;
                        }

                        public function headers(): array
                        {
                            return ['location' => 'https://other.test/x'];
                        }

                        public function header(string $name): ?string
                        {
                            return 'https://other.test/x';
                        }

                        public function text(): string
                        {
                            return '';
                        }

                        public function bytes(): array
                        {
                            return [];
                        }
                    };
                }
            };
        });

        try {
            $transport->send('GET', 'https://example.test/', ['allowed_hosts' => ['example.test']]);
            self::fail('The off-list hop must be refused.');
        } catch (HostNotAllowedException) {
            self::assertSame(1, $calls, 'Only the first request was made.');
        }
    }

    // ---- credentials never reach crawl errors -----------------------------------------------------

    public function test_crawl_errors_never_contain_credentials_or_tokens(): void
    {
        $url = 'https://user:hunter2@8.8.8.8/path?token=SECRETTOKEN&page=1#access_token=FRAGSECRET';
        $transport = new RecordingTransport([], [], 500);
        $spider = new class ($url) extends Spider {
            public function __construct(private string $url)
            {
            }

            public function startRequests(): iterable
            {
                yield SpiderRequest::get($this->url);
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                return [];
            }
        };

        $result = Scraper::create($transport, new InMemorySnapshotStore())->crawl($spider);

        self::assertCount(1, $result->errors);
        $dump = (string) json_encode($result->errors);
        foreach (['hunter2', 'SECRETTOKEN', 'FRAGSECRET', 'user:'] as $secret) {
            self::assertStringNotContainsString($secret, $dump, $secret);
        }
        self::assertStringContainsString('500', $result->errors[0]['message']);
    }

    public function test_exceptions_carry_sanitized_urls(): void
    {
        $url = 'https://u:pw@example.test/x?api_key=KEY123';

        $status = new HttpStatusException(503, $url);
        self::assertStringNotContainsString('KEY123', $status->getMessage());
        self::assertStringNotContainsString('pw', $status->url);

        self::assertStringNotContainsString('KEY123', ResponseTooLargeException::forLimit(10, $url)->getMessage());
        self::assertSame(
            'cURL error 7 for https://example.test/x?token=[REDACTED] (and https://h/?a=1)',
            UrlSanitizer::sanitizeText('cURL error 7 for https://u:p@example.test/x?token=abc (and https://h/?a=1)'),
        );
    }

    public function test_cli_crawl_output_redacts_error_messages(): void
    {
        $file = sys_get_temp_dir() . '/scraper-redact-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, <<<'PHP'
<?php
return new class extends \HElturkey\WebTrawler\Spider\Spider {
    protected array $startUrls = ['https://u:hunter2@8.8.8.8/p?token=SECRETTOKEN'];
    public function parse(\HElturkey\WebTrawler\Parsing\Page $page, \HElturkey\WebTrawler\Spider\SpiderRequest $request): iterable { return []; }
};
PHP);
        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        self::assertIsResource($err);

        try {
            (new Application($out, $err, null, fn (array $o) => new RecordingTransport([], [], 500)))->run(['crawl', $file]);
        } finally {
            unlink($file);
        }
        rewind($err);
        $stderr = (string) stream_get_contents($err);

        self::assertStringContainsString('500', $stderr);
        self::assertStringNotContainsString('hunter2', $stderr);
        self::assertStringNotContainsString('SECRETTOKEN', $stderr);
    }
}
