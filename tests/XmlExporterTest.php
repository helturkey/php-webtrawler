<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Export\JsonExporter;
use HElturkey\WebTrawler\Export\XmlExporter;
use PHPUnit\Framework\TestCase;

final class XmlExporterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/scraper-xml-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path . '*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function test_output_is_well_formed_and_round_trips_values(): void
    {
        $count = (new XmlExporter())->export([
            ['title' => 'Books & <Tags>', 'price' => 12.5, 'ok' => true, 'missing' => null, 'tags' => ['a', 'b'], 'author' => ['name' => 'مايا', 'age' => 30]],
            ['title' => 'Second'],
        ], $this->path . '.xml');

        self::assertSame(2, $count);
        $document = new \DOMDocument();
        self::assertTrue($document->load($this->path . '.xml'), 'The file must be valid XML.');
        $xpath = new \DOMXPath($document);

        self::assertSame('Books & <Tags>', $xpath->evaluate('string(/items/item[1]/title)'));
        self::assertSame('12.5', $xpath->evaluate('string(/items/item[1]/price)'));
        self::assertSame('true', $xpath->evaluate('string(/items/item[1]/ok)'));
        self::assertSame('true', $xpath->evaluate('string(/items/item[1]/missing/@nil)'));
        self::assertSame('b', $xpath->evaluate('string(/items/item[1]/tags/item[2])'));
        self::assertSame('مايا', $xpath->evaluate('string(/items/item[1]/author/name)'));
        self::assertSame(2.0, $xpath->evaluate('count(/items/item)'));
    }

    public function test_hostile_keys_and_values_still_produce_valid_xml(): void
    {
        (new XmlExporter())->export([[
            '1st place' => 'x',
            'a b<c>' => 'y',
            'xmlThing' => 'z',
            '' => 'empty key',
            'ctrl' => "bad\x00\x08char\x0B and \xFF invalid utf8",
        ]], $this->path . '.xml');

        $document = new \DOMDocument();
        self::assertTrue($document->load($this->path . '.xml'));
        $xpath = new \DOMXPath($document);

        self::assertSame('1st place', $xpath->evaluate('string(/items/item/_1st_place/@name)'), 'The original key is preserved.');
        self::assertSame('y', $xpath->evaluate('string(/items/item/a_b_c_)'));
        self::assertSame('z', $xpath->evaluate('string(/items/item/_xmlThing)'));
        self::assertStringStartsWith('badchar and', $xpath->evaluate('string(/items/item/ctrl)'));
    }

    public function test_depth_is_bounded_and_empty_exports_are_valid(): void
    {
        $deep = ['v' => 'end'];
        for ($i = 0; $i < 60; $i++) {
            $deep = ['n' => $deep];
        }
        (new XmlExporter())->export([$deep], $this->path . '.xml');
        $document = new \DOMDocument();
        self::assertTrue($document->load($this->path . '.xml'));
        self::assertGreaterThan(0, $document->getElementsByTagName('n')->length);

        self::assertSame(0, (new XmlExporter())->export([], $this->path . '-empty.xml'));
        self::assertTrue((new \DOMDocument())->load($this->path . '-empty.xml'));

        $this->expectException(\InvalidArgumentException::class);
        (new XmlExporter())->export([], $this->path . '.xml', rootElement: 'bad name');
    }

    public function test_the_json_array_exporter_writes_valid_json(): void
    {
        (new JsonExporter())->export([['a' => 'مرحبا'], ['a' => "x\xB1"]], $this->path . '.json', pretty: true);
        $decoded = json_decode((string) file_get_contents($this->path . '.json'), true);
        self::assertIsArray($decoded);
        self::assertSame('مرحبا', $decoded[0]['a']);
        self::assertCount(2, $decoded);

        (new JsonExporter())->export([], $this->path . '-e.json');
        self::assertSame([], json_decode((string) file_get_contents($this->path . '-e.json'), true));
    }
}
