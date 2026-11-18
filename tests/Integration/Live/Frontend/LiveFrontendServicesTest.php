<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Integration\Live\Frontend;

use HElturkey\WebTrawler\Cli\Application;
use HElturkey\WebTrawler\Mcp\McpServer;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Tests\Support\LiveTargets;
use HElturkey\WebTrawler\Tests\Support\LiveTestCase;
use HElturkey\WebTrawler\Transport\Drivers\GuzzleTransport;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('live')]
final class LiveFrontendServicesTest extends LiveTestCase
{
    public function test_cli_fetch_command_extracts_quotes_from_the_live_example_site(): void
    {
        $stdout = fopen('php://temp', 'w+b');
        $stderr = fopen('php://temp', 'w+b');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        try {
            $application = new Application(
                $stdout,
                $stderr,
                transportFactory: static fn (array $options): GuzzleTransport => new GuzzleTransport(),
            );
            $exitCode = $application->run([
                'fetch',
                LiveTargets::QUOTES_URL,
                '--select', '.quote .text',
                '--block-private',
                '--format', 'json',
            ]);

            rewind($stdout);
            rewind($stderr);
            $output = stream_get_contents($stdout);
            $error = stream_get_contents($stderr);

            self::assertSame(0, $exitCode, (string) $error);
            self::assertIsString($output);
            $rows = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertNotEmpty($rows);
            self::assertNotEmpty($rows[0]['value'] ?? null);
        } finally {
            fclose($stdout);
            fclose($stderr);
        }
    }

    public function test_mcp_extract_tool_fetches_and_extracts_quotes_from_the_live_example_site(): void
    {
        $scraper = Scraper::create(transport: new GuzzleTransport());
        $server = new McpServer($scraper, [
            'allowed_hosts' => [LiveTargets::QUOTES_HOST],
            'timeout' => 45,
            'max_body_bytes' => 8 * 1024 * 1024,
            'block_private_networks' => true,
        ]);
        $request = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ],
                'name' => 'extract',
                'arguments' => [
                    'url' => LiveTargets::QUOTES_URL,
                    'selector' => '.quote .text',
                    'limit' => 3,
                ],
            ],
        ];

        $encoded = json_encode($request, JSON_THROW_ON_ERROR);
        $response = $server->handleMessage($encoded);
        if ($response === null) {
            throw new RuntimeException('MCP tools/call unexpectedly returned no response.');
        }

        $rpc = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($rpc['result']['isError'] ?? true);
        $payload = json_decode($rpc['result']['content'][0]['text'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($payload['matches'] ?? []);
        self::assertGreaterThan(0, $payload['total_matches'] ?? 0);
        self::assertTrue($payload['untrusted_content'] ?? false);
    }
}
