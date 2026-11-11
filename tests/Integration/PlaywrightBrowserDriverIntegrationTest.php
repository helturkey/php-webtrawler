<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Integration;

use HElturkey\WebTrawler\Browser\PlaywrightBrowserDriver;
use HElturkey\WebTrawler\Exception\UnsafeUrlException;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('browser')]
final class PlaywrightBrowserDriverIntegrationTest extends TestCase
{
    /** @var resource|null */
    private $serverProcess = null;

    private ?string $serverLog = null;

    private string $baseUrl = '';

    private string $temporaryDirectory = '';

    protected function setUp(): void
    {
        if (!class_exists(\Playwright\Playwright::class)) {
            self::markTestSkipped('Install the optional driver with composer require playwright-php/playwright.');
        }

        if (getenv('SCRAPER_RUN_BROWSER_INTEGRATION') !== '1') {
            self::markTestSkipped('Set SCRAPER_RUN_BROWSER_INTEGRATION=1 to run Chromium integration tests.');
        }

        if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
            self::markTestSkipped('The local browser fixture requires proc_open() and socket streams.');
        }

        $this->temporaryDirectory = sys_get_temp_dir() . '/php-webtrawler-browser-it-' . bin2hex(random_bytes(5));
        mkdir($this->temporaryDirectory, 0700, true);
        $this->startFixtureServer();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }

        if ($this->serverLog !== null && is_file($this->serverLog)) {
            @unlink($this->serverLog);
        }

        if ($this->temporaryDirectory !== '' && is_dir($this->temporaryDirectory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->temporaryDirectory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->temporaryDirectory);
        }
    }

    public function test_it_captures_fetch_bodies_and_explicit_request_headers(): void
    {
        $page = Scraper::create()->fetchRendered($this->baseUrl . '/fetch', new PlaywrightBrowserDriver(), [
            'wait_until' => 'networkidle',
            'capture_response_bodies' => true,
            'network_capture_patterns' => ['*api/books*'],
        ]);

        self::assertSame('Browser Network Fixture', $page->title());
        self::assertSame('The PHP WebTrawler Handbook', $page->css('#api-result')->first()?->text());

        $apiResponses = $page->apiResponses();
        self::assertNotEmpty($apiResponses);

        $apiResponse = current(array_filter(
            $apiResponses,
            static fn (array $request): bool => str_contains((string) ($request['url'] ?? ''), '/api/books'),
        ));
        self::assertIsArray($apiResponse);
        self::assertSame('fetch', $apiResponse['resource_type']);
        self::assertSame(200, $apiResponse['status']);
        $responseHeaders = array_change_key_case($apiResponse['response_headers'] ?? [], CASE_LOWER);
        self::assertSame('application/json; charset=utf-8', $responseHeaders['content-type'] ?? null);
        self::assertSame(
            (string) strlen('{"items":[{"title":"The PHP WebTrawler Handbook"}]}'),
            $responseHeaders['content-length'] ?? null,
            'The bounded body-capture fixture must declare its exact payload size.',
        );
        self::assertSame('{"items":[{"title":"The PHP WebTrawler Handbook"}]}', $apiResponse['response_body'] ?? null);
        $requestHeaders = array_change_key_case($apiResponse['request_headers'] ?? [], CASE_LOWER);
        self::assertSame('browser-integration', $requestHeaders['x-scraper-fixture'] ?? null);
    }

    public function test_it_persists_cookies_and_local_storage_between_browser_runs(): void
    {
        $scraper = Scraper::create();
        $options = [
            'session_id' => 'phase-three-session',
            'session_directory' => $this->temporaryDirectory . '/sessions',
            'wait_until' => 'load',
        ];

        $scraper->fetchRendered($this->baseUrl . '/seed', new PlaywrightBrowserDriver(), $options);
        $page = $scraper->fetchRendered($this->baseUrl . '/check', new PlaywrightBrowserDriver(), [
            ...$options,
            'wait_for_selector' => '#session-check',
        ]);

        self::assertSame('cookie:yes;local:yes', $page->css('#session-check')->first()?->text());
    }

    public function test_initial_private_navigation_is_rejected_before_chromium_launch(): void
    {
        $this->expectException(UnsafeUrlException::class);

        (new PlaywrightBrowserDriver())->render('http://127.0.0.1/', [
            'block_private_networks' => true,
        ]);
    }

    public function test_browser_cannot_reach_external_sites_in_the_network_isolated_ci_container(): void
    {
        if (getenv('SCRAPER_BROWSER_EGRESS_ISOLATED') !== '1') {
            self::markTestSkipped(
                'This assertion requires external networking to be disabled (for example, docker run --network none).',
            );
        }

        $page = Scraper::create()->fetchRendered($this->baseUrl . '/egress-check', new PlaywrightBrowserDriver(), [
            'wait_until' => 'domcontentloaded',
            'wait_for_selector' => '#egress-check[data-complete="1"]',
            'timeout' => 10_000,
        ]);

        self::assertSame('blocked', $page->css('#egress-check')->first()?->text());
    }

    public function test_rendered_html_is_passed_to_the_adaptive_parser(): void
    {
        $scraper = Scraper::create(snapshotStore: new InMemorySnapshotStore());
        $driver = new PlaywrightBrowserDriver();
        $before = $scraper->fetchRendered($this->baseUrl . '/adaptive-before', $driver);
        $original = $before->css('.book-title')->first();
        self::assertNotNull($original);
        $before->remember('book-title', $original);

        $after = $scraper->fetchRendered($this->baseUrl . '/adaptive-after', $driver);
        $result = $after->adaptive('book-title')->first();

        self::assertTrue($result->accepted, $result->reason ?? 'Adaptive matching should recover the rendered element.');
        self::assertSame('The PHP WebTrawler Handbook', $result->element()?->text());
    }

    private function startFixtureServer(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException(sprintf('Unable to reserve a local test port: %s (%d)', $errorMessage, $errorCode));
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if (!is_string($address) || !str_contains($address, ':')) {
            throw new RuntimeException('Unable to determine the local browser fixture port.');
        }

        $port = (int) substr($address, strrpos($address, ':') + 1);
        $this->baseUrl = 'http://127.0.0.1:' . $port;
        $this->serverLog = $this->temporaryDirectory . '/server.log';
        $router = __DIR__ . '/Fixtures/browser-router.php';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->serverLog, 'a'],
            2 => ['file', $this->serverLog, 'a'],
        ];
        $this->serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            $descriptors,
            $pipes,
        );

        if (!is_resource($this->serverProcess)) {
            throw new RuntimeException('Unable to start the local PHP fixture server.');
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $deadline = microtime(true) + 10;
        do {
            $connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.2);
            if (is_resource($connection)) {
                fclose($connection);

                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('The local PHP fixture server did not become ready: ' . $errorMessage);
    }
}
