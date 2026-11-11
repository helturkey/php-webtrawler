<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Cli\Application;
use HElturkey\WebTrawler\Contracts\AsyncHttpTransport;
use HElturkey\WebTrawler\Http\FetchResponse;
use HElturkey\WebTrawler\Http\Fetcher;
use HElturkey\WebTrawler\Http\GuzzleTransport;
use HElturkey\WebTrawler\Http\RetryPolicy;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Spider\JsonCrawlStateStore;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Spider\SpiderRunner;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

final class SpiderControlFlowTest extends TestCase
{
    private function runner(RecordingTransport $transport): SpiderRunner
    {
        return new SpiderRunner(new Fetcher($transport, new InMemorySnapshotStore(), new AdaptiveMatcher()));
    }

    public function test_fetcher_default_retry_policy_is_used_by_spiders_without_wrapping_async_transport(): void
    {
        $transport = new RecordingTransport(defaultStatus: 503);
        $fetcher = new Fetcher(
            $transport,
            new InMemorySnapshotStore(),
            new AdaptiveMatcher(),
            defaultRetryPolicy: new RetryPolicy(maxAttempts: 3, baseDelayMs: 0, jitter: RetryPolicy::JITTER_NONE),
        );
        $spider = new class () extends Spider {
            public function startRequests(): iterable
            {
                yield SpiderRequest::get('https://example.test/');
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                return [];
            }
        };

        $result = (new SpiderRunner($fetcher))->run($spider);

        self::assertCount(3, $transport->calls, 'SpiderRunner applies the configured retry policy exactly once per attempt.');
        self::assertSame(2, $result->requestsRetried);
        self::assertCount(1, $result->errors);
    }

    // ---- seed consumption ------------------------------------------------------------------------

    private function countingSpider(\stdClass $counter, bool $unique, int $maxPages): Spider
    {
        $spider = new class ($counter, $unique) extends Spider {
            public function __construct(private \stdClass $counter, private bool $unique)
            {
            }

            public function startRequests(): iterable
            {
                for ($i = 0; ; $i++) {
                    $this->counter->yielded++;
                    yield SpiderRequest::get('https://example.test/' . ($this->unique ? $i : 'same'));
                }
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                return [];
            }
        };

        return $spider->configure(['maxPages' => $maxPages]);
    }

    public function test_an_endless_unique_generator_is_never_advanced_beyond_max_pages(): void
    {
        $counter = (object) ['yielded' => 0];
        $result = $this->runner(new RecordingTransport())->run($this->countingSpider($counter, true, 5));

        self::assertSame(5, $counter->yielded, 'Exactly maxPages values may be pulled from the generator, not one more.');
        self::assertSame(5, $result->requestsCompleted);
    }

    public function test_an_endless_generator_of_duplicates_terminates(): void
    {
        $counter = (object) ['yielded' => 0];
        $result = $this->runner(new RecordingTransport())->run($this->countingSpider($counter, false, 5));

        self::assertSame(5, $counter->yielded);
        self::assertSame(1, $result->requestsScheduled, 'Only one distinct request exists.');
        self::assertSame(1, $result->requestsCompleted);
    }

    public function test_finite_seed_lists_arrays_and_traversables_are_read_completely(): void
    {
        foreach ([
            'array' => static fn (): array => [SpiderRequest::get('https://example.test/1'), SpiderRequest::get('https://example.test/2')],
            'aggregate' => static fn (): \IteratorAggregate => new \ArrayObject([SpiderRequest::get('https://example.test/1'), SpiderRequest::get('https://example.test/2')]),
            'generator' => static function (): \Generator {
                yield SpiderRequest::get('https://example.test/1');
                yield SpiderRequest::get('https://example.test/2');
            },
        ] as $kind => $factory) {
            $spider = new class ($factory) extends Spider {
                public function __construct(private \Closure $factory)
                {
                }

                public function startRequests(): iterable
                {
                    return ($this->factory)();
                }

                public function parse(Page $page, SpiderRequest $request): iterable
                {
                    return [];
                }
            };

            self::assertSame(2, $this->runner(new RecordingTransport())->run($spider)->requestsCompleted, $kind);
        }
    }

    public function test_resumable_state_rejects_non_json_safe_spider_request_payloads(): void
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        $path = sys_get_temp_dir() . '/scraper-invalid-state-' . bin2hex(random_bytes(4)) . '.json';
        $state = new JsonCrawlStateStore($path);
        $spider = new class ($stream) extends Spider {
            /** @param resource $stream */
            public function __construct(private mixed $stream)
            {
            }

            public function startRequests(): iterable
            {
                yield new SpiderRequest('https://example.test/upload', 'POST', null, 0, ['body' => $this->stream]);
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                return [];
            }
        };

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('SpiderRequest extra option "body" is not JSON-safe');
            $this->runner(new RecordingTransport())->run($spider, $state, static fn (): bool => true);
        } finally {
            fclose($stream);
            @unlink($path);
        }
    }

    public function test_resumable_state_rejects_binary_request_bodies_instead_of_corrupting_them(): void
    {
        $path = sys_get_temp_dir() . '/scraper-binary-state-' . bin2hex(random_bytes(4)) . '.json';
        $state = new JsonCrawlStateStore($path);
        $spider = new class () extends Spider {
            public function startRequests(): iterable
            {
                yield new SpiderRequest('https://example.test/upload', 'POST', null, 0, ['body' => "\xFF\xFEbinary"]);
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                return [];
            }
        };

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('SpiderRequest extra option "body" is not JSON-safe');
            $this->runner(new RecordingTransport())->run($spider, $state, static fn (): bool => true);
        } finally {
            @unlink($path);
        }
    }

    // ---- interruption with downloads still in flight -----------------------------------------------

    public function test_stopping_a_concurrent_crawl_with_pending_downloads_reports_interrupted_and_saves_them(): void
    {
        $transport = new class () implements AsyncHttpTransport {
            public function supportsAsync(): bool
            {
                return true;
            }

            public function send(string $method, string $url, array $options = []): FetchResponse
            {
                throw new \LogicException('async only');
            }

            public function sendAsync(string $method, string $url, array $options = []): PromiseInterface
            {
                static $first = true;
                $isFirst = $first;
                $first = false;
                $promise = new Promise(static function () use (&$promise, $isFirst, $url): void {
                    // Only the first download ever completes; the others stay in flight.
                    if ($isFirst && $promise instanceof Promise) {
                        $promise->resolve(new FetchResponse(200, [], '<p>x</p>', $url, $url, 1.0));
                    }
                });

                return $promise;
            }
        };

        $spider = new class () extends Spider {
            public function startRequests(): iterable
            {
                foreach ([1, 2, 3] as $i) {
                    yield SpiderRequest::get('https://example.test/' . $i);
                }
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield ['url' => $page->url()];
            }
        };
        $spider->configure(['concurrency' => 3]);

        $path = sys_get_temp_dir() . '/scraper-inflight-' . bin2hex(random_bytes(4)) . '.json';
        $state = new JsonCrawlStateStore($path);
        $polls = 0;

        try {
            $result = (new SpiderRunner(new Fetcher($transport, new InMemorySnapshotStore(), new AdaptiveMatcher())))
                ->run($spider, $state, static function () use (&$polls): bool {
                    return ++$polls > 1;   // stop right after the first download has been processed
                });

            self::assertSame(1, $result->requestsCompleted);
            self::assertTrue($result->interrupted, 'Two downloads were still in flight, so the crawl is not complete.');
            $saved = $state->load();
            self::assertIsArray($saved);
            self::assertCount(2, $saved['pending'], 'In-flight downloads are saved so a resume repeats them.');
        } finally {
            @unlink($path);
        }
    }

    // ---- CLI: shared options, retries and concurrency ---------------------------------------------

    private function spiderFile(string $body): string
    {
        $file = sys_get_temp_dir() . '/scraper-cli-spider-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, "<?php\n" . $body);

        return $file;
    }

    /** @param list<string> $argv @return array{0: int, 1: string} */
    private function cli(array $argv, \Closure $factory): array
    {
        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = (new Application($out, $err, null, $factory))->run($argv);
        rewind($err);

        return [$code, (string) stream_get_contents($err)];
    }

    public function test_cli_crawl_applies_the_shared_fetch_options_and_enforces_the_security_ones(): void
    {
        $file = $this->spiderFile(<<<'PHP'
use HElturkey\WebTrawler\Http\FetchOptions;
use HElturkey\WebTrawler\Spider\SpiderRequest;
return new class extends \HElturkey\WebTrawler\Spider\Spider {
    public function startRequests(): iterable
    {
        yield SpiderRequest::get('https://8.8.8.8/plain');
        // A spider that brings its own options must not be able to switch the CLI's policy off.
        yield SpiderRequest::get('https://8.8.8.8/explicit', new FetchOptions(blockPrivateNetworks: false, maxBodyBytes: 99_999_999, timeout: 5.0));
        yield SpiderRequest::get('http://127.0.0.1/internal', new FetchOptions(blockPrivateNetworks: false));
    }
    public function parse(\HElturkey\WebTrawler\Parsing\Page $page, SpiderRequest $request): iterable { yield ['u' => $page->url()]; }
};
PHP);
        $transport = new RecordingTransport();

        try {
            [$code, $stderr] = $this->cli(
                ['crawl', $file, '--block-private', '--max-bytes', '1234', '--timeout', '7', '--header', 'X-Cli: 1', '--encoding', 'utf-8'],
                fn (array $options) => $transport,
            );
        } finally {
            unlink($file);
        }

        self::assertSame(0, $code);
        self::assertCount(2, $transport->calls, 'The loopback request must never reach the transport.');
        self::assertStringContainsString('non-public', $stderr);

        [$plain, $explicit] = array_column($transport->calls, 'options');
        self::assertTrue($plain['block_private_networks']);
        self::assertSame(1234, $plain['max_body_bytes']);
        self::assertSame(7.0, $plain['timeout']);
        self::assertSame(['X-Cli' => '1'], $plain['headers']);

        self::assertTrue($explicit['block_private_networks'], 'Enforced even though the spider asked for false.');
        self::assertSame(1234, $explicit['max_body_bytes'], 'The stricter limit wins.');
        self::assertSame(5.0, $explicit['timeout'], 'Non-security options of an explicit request are respected.');
    }

    private function guzzleMock(int ...$statuses): array
    {
        $mock = new MockHandler(array_map(static fn (int $status): Response => new Response($status, [], '<p>x</p>'), $statuses));

        return [$mock, new GuzzleTransport(new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]))];
    }

    private function threePageSpider(): string
    {
        return $this->spiderFile(<<<'PHP'
use HElturkey\WebTrawler\Spider\SpiderRequest;
return new class extends \HElturkey\WebTrawler\Spider\Spider {
    public function startRequests(): iterable { foreach ([1, 2, 3] as $i) { yield SpiderRequest::get('https://example.test/' . $i); } }
    public function parse(\HElturkey\WebTrawler\Parsing\Page $page, SpiderRequest $request): iterable { yield ['url' => $page->url()]; }
};
PHP);
    }

    public function test_cli_crawl_supports_concurrency_together_with_retries(): void
    {
        [$mock, $transport] = $this->guzzleMock(200, 200, 200);
        $file = $this->threePageSpider();
        $output = $file . '.jsonl';

        try {
            [$code, $stderr] = $this->cli(['crawl', $file, '--concurrency', '4', '--retries', '3', '--retry-delay', '0', '--output', $output], fn (array $o) => $transport);
        } finally {
            unlink($file);
        }

        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString('Completed 3 pages', $stderr);
        self::assertCount(0, $mock);
        @unlink($output);
    }

    public function test_cli_retries_are_applied_once_not_at_two_layers(): void
    {
        // A permanently failing page with --retries 3 means exactly 4 attempts (1 + 3), not 4 x 4 = 16.
        [$mock, $transport] = $this->guzzleMock(...array_fill(0, 20, 503));
        $file = $this->spiderFile(<<<'PHP'
use HElturkey\WebTrawler\Spider\SpiderRequest;
return new class extends \HElturkey\WebTrawler\Spider\Spider {
    protected array $startUrls = ['https://example.test/down'];
    public function parse(\HElturkey\WebTrawler\Parsing\Page $page, SpiderRequest $request): iterable { return []; }
};
PHP);

        try {
            $this->cli(['crawl', $file, '--retries', '3', '--retry-delay', '0'], fn (array $o) => $transport);
        } finally {
            unlink($file);
        }

        self::assertSame(16, count($mock), '20 queued responses minus exactly 4 consumed attempts.');
    }

    public function test_fetch_still_retries_through_the_blocking_wrapper(): void
    {
        [$mock, $transport] = $this->guzzleMock(503, 200);
        $err = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = (new Application($out, $err, null, fn (array $o) => $transport))->run(['fetch', 'https://example.test/', '--retries', '1', '--retry-delay', '0', '--select', 'p', '--format', 'text']);
        rewind($out);

        self::assertSame(0, $code);
        self::assertSame("x\n", stream_get_contents($out));
        self::assertCount(0, $mock);
    }

    // ---- no hard mbstring dependency ---------------------------------------------------------------

    public function test_every_mbstring_call_in_the_library_is_guarded(): void
    {
        $unguarded = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../src')) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/\bmb_[a-z_]+\s*\(/', $code) === 1 && preg_match('/function_exists\(\s*[\'"]mb_/', $code) !== 1) {
                $unguarded[] = substr($file->getPathname(), (int) strpos($file->getPathname(), 'src/'));
            }
        }

        self::assertSame([], $unguarded, 'ext-mbstring is only suggested; calls need a function_exists() fallback.');
    }

    public function test_text_length_matches_unicode_code_points(): void
    {
        self::assertSame(5, \HElturkey\WebTrawler\Support\Text::lengthChars('مرحبا'));
        self::assertSame(3, \HElturkey\WebTrawler\Support\Text::lengthChars('a😀é'));
        self::assertSame(0, \HElturkey\WebTrawler\Support\Text::lengthChars(''));
    }
}
