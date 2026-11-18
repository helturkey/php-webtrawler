<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Integration\Live\Export;

use HElturkey\WebTrawler\Export\CsvExporter;
use HElturkey\WebTrawler\Export\JsonExporter;
use HElturkey\WebTrawler\Export\JsonlExporter;
use HElturkey\WebTrawler\Export\XmlExporter;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Tests\Support\LiveTargets;
use HElturkey\WebTrawler\Tests\Support\LiveTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('live')]
final class LiveExportServicesTest extends LiveTestCase
{
    public function test_all_exporters_write_records_extracted_from_the_live_quotes_page(): void
    {
        if (!extension_loaded('xmlwriter')) {
            self::markTestSkipped('ext-xmlwriter is required for the XML exporter integration test.');
        }

        $page = Scraper::create()->get(LiveTargets::QUOTES_URL, $this->liveOptions([LiveTargets::QUOTES_HOST]));
        $quotes = $this->extractQuotes($page);
        self::assertNotEmpty($quotes);

        $directory = sys_get_temp_dir() . '/webtrawler-live-export-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700, true));

        try {
            $jsonPath = $directory . '/quotes.json';
            $jsonlPath = $directory . '/quotes.jsonl';
            $csvPath = $directory . '/quotes.csv';
            $xmlPath = $directory . '/quotes.xml';

            self::assertSame(count($quotes), (new JsonExporter())->export($quotes, $jsonPath));
            self::assertSame(count($quotes), (new JsonlExporter())->export($quotes, $jsonlPath));
            self::assertSame(count($quotes), (new CsvExporter())->export($quotes, $csvPath, headers: ['text', 'author']));
            self::assertSame(count($quotes), (new XmlExporter())->export($quotes, $xmlPath, rootElement: 'quotes', itemElement: 'quote'));

            $decodedJson = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
            self::assertCount(count($quotes), $decodedJson);
            self::assertStringContainsString($quotes[0]['author'], (string) file_get_contents($jsonlPath));
            self::assertStringContainsString('author', (string) file_get_contents($csvPath));
            self::assertStringContainsString('<quotes>', (string) file_get_contents($xmlPath));
            self::assertStringContainsString('<quote>', (string) file_get_contents($xmlPath));
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }
}
