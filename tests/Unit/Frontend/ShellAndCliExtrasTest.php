<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Frontend;

use HElturkey\WebTrawler\Cli\Application;
use HElturkey\WebTrawler\Contracts\Http\TransportInterface;
use HElturkey\WebTrawler\Response\FetchResponse;
use PHPUnit\Framework\TestCase;

final class ShellAndCliExtrasTest extends TestCase
{
    public const PAGE = '<html><head><title>Shop</title><script>evil()</script></head><body>'
        . '<h1>Catalogue</h1><ul><li class="p"><a href="/a">Alpha</a> <b>=SUM(1)</b></li><li class="p"><a href="/b">Beta</a></li></ul>'
        . '</body></html>';

    /** @var list<string> */
    private array $requested = [];

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    private function transport(): TransportInterface
    {
        return new class ($this->requested) implements TransportInterface {
            /** @param list<string> $requested */
            public function __construct(private array &$requested)
            {
            }

            public function send(string $method, string $url, array $options = []): FetchResponse
            {
                $this->requested[] = $url;
                $status = str_ends_with($url, '/robots.txt') ? 404 : 200;

                return new FetchResponse($status, ['Content-Type' => ['text/html']], $status === 200 ? ShellAndCliExtrasTest::PAGE : '', $url, $url, 1.0);
            }
        };
    }

    /**
     * @param list<string> $argv
     * @return array{int, string, string}
     */
    private function runCli(array $argv, string $stdin = ''): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $in = fopen('php://memory', 'w+');
        fwrite($in, $stdin);
        rewind($in);

        $application = new Application($out, $err, null, fn (array $options): TransportInterface => $this->transport(), null, $in);
        $code = $application->run($argv);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function test_the_shell_explores_a_page_without_executing_anything(): void
    {
        [$code, $out] = $this->runCli(['shell'], "fetch https://shop.test/\ntitle\ncount li.p\nattr href a\nlinks\ncss li.p\nxpath //h1\nmarkdown h1\nbogus\nphp_info()\nquit\n");

        self::assertSame(0, $code);
        self::assertStringContainsString('200 Shop (https://shop.test/)', $out);
        self::assertMatchesRegularExpression('/^Shop$/m', $out);
        self::assertMatchesRegularExpression('/^2$/m', $out);
        self::assertStringContainsString("/a\n/b\n", $out);
        self::assertStringContainsString('https://shop.test/a', $out);
        self::assertStringContainsString('2 links', $out);
        self::assertStringContainsString('[0] <li> Alpha =SUM(1)', $out);
        self::assertStringContainsString('[0] <h1> Catalogue', $out);
        self::assertStringContainsString('# Catalogue', $out);
        self::assertStringContainsString('Unknown command "bogus"', $out);
        self::assertStringContainsString('Unknown command "php_info()"', $out);
    }

    public function test_the_shell_takes_a_starting_url_and_reports_errors_without_dying(): void
    {
        [$code, $out] = $this->runCli(['shell', 'https://shop.test/'], "css\nfetch\nattr href\nquit\n");

        self::assertSame(0, $code);
        self::assertStringContainsString('200 Shop', $out);
        self::assertStringContainsString('Error: Usage: css <selector>', $out);
        self::assertStringContainsString('Error: Usage: fetch <url>', $out);
        self::assertStringContainsString('Error: Usage: attr <name> <selector>', $out);
    }

    public function test_the_shell_stops_at_the_end_of_input(): void
    {
        [$code] = $this->runCli(['shell'], "help\n");

        self::assertSame(0, $code);
    }

    public function test_commands_before_any_fetch_explain_what_to_do(): void
    {
        [, $out] = $this->runCli(['shell'], "title\nquit\n");

        self::assertStringContainsString('Error: No page yet. Use: fetch <url>', $out);
    }

    public function test_fetch_can_print_markdown(): void
    {
        [$code, $out] = $this->runCli(['fetch', 'https://shop.test/', '--markdown']);

        self::assertSame(0, $code);
        self::assertStringContainsString('# Catalogue', $out);
        self::assertStringContainsString('- [Alpha](https://shop.test/a) **=SUM(1)**', $out);
        self::assertStringNotContainsString('evil()', $out);
    }

    public function test_fetch_can_write_markdown_to_a_file(): void
    {
        $directory = sys_get_temp_dir() . '/scraper-md-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700);
        $this->cleanup[] = $directory;

        [$code] = $this->runCli(['fetch', 'https://shop.test/', '--markdown', '--select', 'ul', '--output', $directory . '/page.md']);

        self::assertSame(0, $code);
        self::assertStringContainsString('[Beta](https://shop.test/b)', (string) file_get_contents($directory . '/page.md'));
    }

    public function test_the_development_cache_avoids_repeat_downloads_and_offline_replays_it(): void
    {
        $directory = sys_get_temp_dir() . '/scraper-cache-' . bin2hex(random_bytes(4));
        $this->cleanup[] = $directory;

        $this->runCli(['fetch', 'https://shop.test/', '--cache-dir', $directory, '--markdown']);
        $this->runCli(['fetch', 'https://shop.test/', '--cache-dir', $directory, '--markdown']);
        self::assertSame(['https://shop.test/'], $this->requested);

        [$code, $out] = $this->runCli(['fetch', 'https://shop.test/', '--cache-dir', $directory, '--offline', '--markdown']);
        self::assertSame(0, $code);
        self::assertStringContainsString('# Catalogue', $out);
        self::assertSame(['https://shop.test/'], $this->requested);
    }

    public function test_offline_needs_a_cache_directory(): void
    {
        [$code, , $err] = $this->runCli(['fetch', 'https://shop.test/', '--offline']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('--offline needs --cache-dir', $err);
    }
}
