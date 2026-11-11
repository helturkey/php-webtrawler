<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Export\CsvExporter;
use HElturkey\WebTrawler\Export\JsonlExporter;
use PHPUnit\Framework\TestCase;

final class ExporterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/scraper-export-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);
    }

    /** @return list<string> */
    private function files(): array
    {
        return array_values(array_map('basename', array_filter(glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [], 'is_file')));
    }

    public function test_jsonl_writes_newline_terminated_lines_and_substitutes_invalid_utf8(): void
    {
        $path = $this->directory . '/out.jsonl';
        $count = (new JsonlExporter())->export([['a' => 'مرحبا'], ['a' => "bad\xB1byte"]], $path);

        self::assertSame(2, $count);
        $lines = explode("\n", (string) file_get_contents($path));
        self::assertSame('{"a":"مرحبا"}', $lines[0]);
        self::assertSame('', $lines[2]);
        self::assertIsArray(json_decode($lines[1], true));
        self::assertStringNotContainsString("\r", (string) file_get_contents($path));
    }

    public function test_a_failing_export_keeps_the_previous_file_and_leaves_no_temp_files(): void
    {
        $path = $this->directory . '/out.jsonl';
        file_put_contents($path, "previous\n");

        try {
            (new JsonlExporter())->export((static function () {
                yield ['ok' => 1];
                yield ['bad' => NAN];
            })(), $path);
            self::fail('Expected a JsonException.');
        } catch (\JsonException) {
            self::assertSame("previous\n", file_get_contents($path));
            self::assertSame(['out.jsonl'], $this->files());
        }
    }

    public function test_csv_handles_bom_and_formula_neutralization_as_opt_in(): void
    {
        $rows = [['name' => '=HYPERLINK("http://evil","x")', 'n' => -5, 'tags' => ['a', 'b'], 'ok' => true]];

        $plain = $this->directory . '/plain.csv';
        (new CsvExporter())->export($rows, $plain);
        self::assertStringContainsString('"=HYPERLINK', (string) file_get_contents($plain));

        $safe = $this->directory . '/safe.csv';
        (new CsvExporter())->export($rows, $safe, utf8Bom: true, neutralizeFormulas: true);
        $content = (string) file_get_contents($safe);
        self::assertStringStartsWith("\xEF\xBB\xBFname,n,tags,ok\n", $content);
        self::assertStringContainsString("\"'=HYPERLINK", $content);
        self::assertStringContainsString(',-5,', $content, 'Numbers are not altered.');
        self::assertStringContainsString('"[""a"",""b""]",true', $content);
    }

    public function test_csv_rejects_multibyte_delimiters_and_missing_directories(): void
    {
        try {
            (new CsvExporter())->export([], $this->directory . '/x.csv', ';;');
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(\RuntimeException::class);
        (new JsonlExporter())->export([], $this->directory . '/missing/out.jsonl');
    }
}
