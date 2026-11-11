<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Cli\Application;
use HElturkey\WebTrawler\Contracts\HttpTransport;
use HElturkey\WebTrawler\Http\FetchResponse;
use HElturkey\WebTrawler\Mcp\McpServer;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

final class CliAndMcpTest extends TestCase
{
    private const SITE = [
        'https://shop.test/' => [200, '<html><head><title>Shop</title></head><body><ul><li class="p"><a href="/a">Alpha</a> <b>=SUM(1)</b></li><li class="p"><a href="/b">Beta</a> <b>9</b></li></ul><script>evil()</script></body></html>'],
        'https://shop.test/a' => [200, '<h1>A</h1>'],
        'https://shop.test/b' => [200, '<h1>B</h1>'],
    ];

    private function transport(): HttpTransport
    {
        return new class (self::SITE) implements HttpTransport {
            /** @param array<string, array{int, string}> $site */
            public function __construct(private array $site)
            {
            }

            public function send(string $method, string $url, array $options = []): FetchResponse
            {
                [$status, $body] = $this->site[$url] ?? [404, ''];

                return new FetchResponse($status, ['Content-Type' => ['text/html; charset=utf-8']], $body, $url, $url, 1.0);
            }
        };
    }

    /** @param list<string> $argv @return array{0: int, 1: string, 2: string} */
    private function cli(array $argv): array
    {
        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = (new Application($out, $err, null, fn (array $options): HttpTransport => $this->transport()))->run($argv);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function test_fetch_extracts_structured_rows_in_every_format(): void
    {
        $args = ['fetch', 'https://shop.test/', '--item', 'li.p', '--field', 'name=a', '--field', 'link=a@href', '--field', 'price=b'];

        [$code, $json] = $this->cli([...$args, '--format', 'json']);
        self::assertSame(0, $code);
        self::assertSame([['name' => 'Alpha', 'link' => '/a', 'price' => '=SUM(1)'], ['name' => 'Beta', 'link' => '/b', 'price' => '9']], json_decode($json, true));

        [, $jsonl] = $this->cli([...$args, '--format', 'jsonl']);
        self::assertCount(2, array_filter(explode("\n", $jsonl)));

        [, $csv] = $this->cli([...$args, '--format', 'csv']);
        self::assertStringContainsString("'=SUM(1)", $csv, 'CLI CSV output neutralizes formulas by default.');

        [, $xml] = $this->cli([...$args, '--format', 'xml']);
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
        self::assertSame('Beta', (new \DOMXPath($document))->evaluate('string(/items/item[2]/name)'));
    }

    public function test_fetch_select_attr_summary_and_text_modes(): void
    {
        [, $text] = $this->cli(['fetch', 'https://shop.test/', '--select', 'li a', '--attr', 'href', '--format', 'text']);
        self::assertSame("/a\n/b\n", $text);

        [, $summary] = $this->cli(['fetch', 'https://shop.test/']);
        $row = json_decode($summary, true)[0];
        self::assertSame('Shop', $row['title']);
        self::assertSame(200, $row['status']);
        self::assertStringNotContainsString('evil()', $row['text'], 'Script content never leaks into page text.');
    }

    public function test_usage_errors_exit_with_code_two_and_runtime_errors_with_one(): void
    {
        foreach ([['fetch'], ['fetch', 'https://shop.test/', '--nope'], ['bogus'], ['fetch', 'https://shop.test/', '--format', 'yaml'], ['fetch', 'https://shop.test/', '--select', 'a', '--xpath', '//a'], ['fetch', 'https://shop.test/', '--item', 'li'], ['crawl', '/no/such/file.php']] as $argv) {
            [$code, , $err] = $this->cli($argv);
            self::assertSame(2, $code, implode(' ', $argv));
            self::assertStringStartsWith('Error:', $err);
        }

        [$code, , $err] = $this->cli(['fetch', 'not a url']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Only valid HTTP', $err);
    }

    public function test_crawl_runs_a_spider_file_and_applies_overrides(): void
    {
        $file = sys_get_temp_dir() . '/scraper-spider-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, <<<'PHP'
<?php
return new class extends \HElturkey\WebTrawler\Spider\Spider {
    protected array $startUrls = ['https://shop.test/'];
    public function parse(\HElturkey\WebTrawler\Parsing\Page $page, \HElturkey\WebTrawler\Spider\SpiderRequest $request): iterable
    {
        yield ['url' => $page->url()];
        foreach ($page->css('li a') as $a) { yield \HElturkey\WebTrawler\Spider\SpiderRequest::get((string) $a->attr('href')); }
    }
};
PHP);
        $output = $file . '.jsonl';

        try {
            [$code, , $err] = $this->cli(['crawl', $file, '--max-pages', '2', '--output', $output]);
            self::assertSame(0, $code);
            self::assertStringContainsString('Completed 2 pages, 2 items', $err);
            self::assertCount(2, file($output, FILE_IGNORE_NEW_LINES));

            [$code, , $err] = $this->cli(['crawl', $file, '--state', $file . '.state']);
            self::assertSame(2, $code, '--state requires a streaming jsonl output.');
            self::assertStringContainsString('--state needs', $err);
        } finally {
            @unlink($file);
            @unlink($output);
        }
    }

    private function mcp(): McpServer
    {
        $server = new McpServer(Scraper::create($this->transport(), new InMemorySnapshotStore()), ['block_private_networks' => false]);
        $server->handleMessage((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 'legacy-bootstrap',
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25'],
        ]));

        return $server;
    }

    /** @return array<string, mixed> */
    private function rpc(McpServer $server, array $message): array
    {
        $response = $server->handleMessage((string) json_encode($message));
        self::assertNotNull($response);

        return json_decode($response, true);
    }

    public function test_mcp_handshake_lists_tools_and_negotiates_the_protocol_version(): void
    {
        $server = $this->mcp();

        $init = $this->rpc($server, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05']]);
        self::assertSame('2024-11-05', $init['result']['protocolVersion']);
        $init = $this->rpc($server, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]);
        self::assertSame('2025-11-25', $init['result']['protocolVersion']);

        self::assertNull($server->handleMessage('{"jsonrpc":"2.0","method":"notifications/initialized"}'), 'Notifications get no response.');

        $tools = $this->rpc($server, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);
        self::assertSame(['fetch_page', 'extract'], array_column($tools['result']['tools'], 'name'));
        foreach ($tools['result']['tools'] as $tool) {
            self::assertSame('object', $tool['inputSchema']['type']);
            self::assertTrue($tool['annotations']['readOnlyHint']);
        }
    }

    public function test_mcp_stateless_discovery_and_per_request_protocol_versions(): void
    {
        $server = $this->mcp();
        $meta = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];

        $discovery = $this->rpc($server, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => $meta],
        ]);
        self::assertSame('complete', $discovery['result']['resultType']);
        self::assertContains('2026-07-28', $discovery['result']['supportedVersions']);
        self::assertArrayHasKey('io.modelcontextprotocol/serverInfo', $discovery['result']['_meta']);

        $tools = $this->rpc($server, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => ['_meta' => $meta],
        ]);
        self::assertSame(['fetch_page', 'extract'], array_column($tools['result']['tools'], 'name'));
        self::assertSame('complete', $tools['result']['resultType']);
        self::assertArrayHasKey('io.modelcontextprotocol/serverInfo', $tools['result']['_meta']);

        $call = $this->rpc($server, [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                '_meta' => $meta,
                'name' => 'fetch_page',
                'arguments' => ['url' => 'https://shop.test/'],
            ],
        ]);
        self::assertSame('complete', $call['result']['resultType']);
        self::assertArrayHasKey('io.modelcontextprotocol/serverInfo', $call['result']['_meta']);
    }

    public function test_mcp_rejects_legacy_requests_before_initialize(): void
    {
        $server = new McpServer(Scraper::create($this->transport(), new InMemorySnapshotStore()), ['block_private_networks' => false]);
        $response = $this->rpc($server, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        self::assertSame(-32602, $response['error']['code']);
        self::assertStringContainsString('initialize handshake', $response['error']['message']);
    }

    public function test_mcp_requires_modern_client_capabilities_metadata(): void
    {
        $server = $this->mcp();
        $response = $this->rpc($server, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_mcp_rejects_unsupported_per_request_protocol_before_tool_execution(): void
    {
        $transport = new RecordingTransport();
        $server = new McpServer(Scraper::create($transport, new InMemorySnapshotStore()), ['block_private_networks' => false]);
        $response = $this->rpc($server, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2099-01-01'],
                'name' => 'fetch_page',
                'arguments' => ['url' => 'https://shop.test/'],
            ],
        ]);

        self::assertSame(-32022, $response['error']['code']);
        self::assertContains('2026-07-28', $response['error']['data']['supported']);
        self::assertSame([], $transport->calls, 'An unsupported protocol request must not start a fetch.');
    }

    public function test_mcp_tools_return_untrusted_labelled_json_and_report_failures_as_tool_errors(): void
    {
        $server = $this->mcp();

        $page = $this->rpc($server, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'fetch_page', 'arguments' => ['url' => 'https://shop.test/', 'include_links' => true]]]);
        self::assertFalse($page['result']['isError']);
        $payload = json_decode($page['result']['content'][0]['text'], true);
        self::assertSame('Shop', $payload['title']);
        self::assertTrue($payload['untrusted_content']);
        self::assertSame('https://shop.test/a', $payload['links'][0]['url']);
        self::assertStringNotContainsString('evil()', $payload['text']);

        $extract = $this->rpc($server, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'extract', 'arguments' => ['url' => 'https://shop.test/', 'selector' => 'li a', 'attribute' => 'href', 'limit' => 1]]]);
        $payload = json_decode($extract['result']['content'][0]['text'], true);
        self::assertSame(['/a'], $payload['matches']);
        self::assertSame(2, $payload['total_matches']);

        $failed = $this->rpc($server, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'fetch_page', 'arguments' => ['url' => 'not a url']]]);
        self::assertTrue($failed['result']['isError']);
    }

    public function test_mcp_protocol_errors_use_the_standard_json_rpc_codes(): void
    {
        $server = $this->mcp();

        self::assertSame(-32700, json_decode((string) $server->handleMessage('not json'), true)['error']['code']);
        self::assertSame(-32600, $this->rpc($server, ['id' => 1, 'method' => 'ping'])['error']['code']);
        self::assertSame(-32601, $this->rpc($server, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'nope'])['error']['code']);
        self::assertSame(-32602, $this->rpc($server, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'rm_rf', 'arguments' => []]])['error']['code']);
        self::assertSame(-32602, $this->rpc($server, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'extract', 'arguments' => ['url' => 'https://shop.test/']]])['error']['code']);
        self::assertSame([], $this->rpc($server, ['jsonrpc' => '2.0', 'id' => 'abc', 'method' => 'ping'])['result']);

        $batch = json_decode((string) $server->handleMessage('[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","method":"notifications/initialized"}]'), true);
        self::assertCount(1, $batch);
    }

    public function test_mcp_defaults_block_private_networks_and_enforce_the_host_allow_list(): void
    {
        $strict = new McpServer(Scraper::create($this->transport(), new InMemorySnapshotStore()));
        $modernParams = ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => []]];
        $result = $this->rpc($strict, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $modernParams + ['name' => 'fetch_page', 'arguments' => ['url' => 'http://169.254.169.254/latest/meta-data/']]]);
        self::assertTrue($result['result']['isError']);
        self::assertStringContainsString('non-public', $result['result']['content'][0]['text']);

        $limited = new McpServer(Scraper::create($this->transport(), new InMemorySnapshotStore()), ['allowed_hosts' => ['other.test'], 'block_private_networks' => false]);
        $result = $this->rpc($limited, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $modernParams + ['name' => 'fetch_page', 'arguments' => ['url' => 'https://shop.test/']]]);
        self::assertTrue($result['result']['isError']);
        self::assertStringContainsString('allow-list', $result['result']['content'][0]['text']);
    }

    public function test_mcp_serve_loop_reads_lines_and_writes_one_response_per_request(): void
    {
        $input = fopen('php://memory', 'w+b');
        $output = fopen('php://memory', 'w+b');
        self::assertIsResource($input);
        self::assertIsResource($output);
        fwrite($input, "{\"jsonrpc\":\"2.0\",\"id\":0,\"method\":\"initialize\",\"params\":{\"protocolVersion\":\"2025-11-25\"}}\n{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}\n\n{\"jsonrpc\":\"2.0\",\"method\":\"notifications/initialized\"}\n{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/list\"}\n");
        rewind($input);

        $this->mcp()->serve($input, $output);
        rewind($output);
        $lines = array_filter(explode("\n", (string) stream_get_contents($output)));

        self::assertCount(3, $lines);
        self::assertSame(0, json_decode($lines[0], true)['id']);
        self::assertSame(1, json_decode(array_values($lines)[1], true)['id']);
        self::assertSame(2, json_decode(array_values($lines)[2], true)['id']);
    }

    public function test_spider_configure_rejects_unknown_settings(): void
    {
        $spider = new class () extends Spider {
            public function parse(Page $page, SpiderRequest $request): iterable
            {
                return [];
            }
        };
        self::assertSame(7, $spider->configure(['concurrency' => 7])->concurrency());

        $this->expectException(\InvalidArgumentException::class);
        $spider->configure(['nonsense' => 1]);
    }
}
