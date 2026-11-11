<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Export;

use InvalidArgumentException;
use RuntimeException;
use XMLWriter;

/**
 * Streams items to a well-formed XML 1.0 document.
 *
 *   <items><item><title>…</title><tags><item>a</item><item>b</item></tags></item></items>
 *
 * Keys that are not valid XML names are sanitized and the original is kept in a name="" attribute.
 * Invalid XML characters are removed and invalid UTF-8 is replaced, so the output always parses.
 */
final class XmlExporter
{
    private const MAX_DEPTH = 32;

    /** @param iterable<array<string, mixed>> $items */
    public function export(iterable $items, string $filePath, string $rootElement = 'items', string $itemElement = 'item', bool $pretty = true): int
    {
        if (!extension_loaded('xmlwriter')) {
            throw new RuntimeException('XmlExporter requires ext-xmlwriter.');
        }
        foreach ([$rootElement, $itemElement] as $name) {
            if (self::sanitizeName($name) !== $name) {
                throw new InvalidArgumentException(sprintf('"%s" is not a valid XML element name.', $name));
            }
        }

        return AtomicFile::write($filePath, function ($stream) use ($items, $rootElement, $itemElement, $pretty): int {
            $writer = new XMLWriter();
            $writer->openMemory();
            $writer->setIndent($pretty);
            $writer->setIndentString('  ');
            $writer->startDocument('1.0', 'UTF-8');
            $writer->startElement($rootElement);
            AtomicFile::writeAll($stream, (string) $writer->flush(true));

            $count = 0;
            foreach ($items as $item) {
                $this->writeValue($writer, $itemElement, $item, 0);
                AtomicFile::writeAll($stream, (string) $writer->flush(true));
                $count++;
            }

            $writer->endElement();
            $writer->endDocument();
            AtomicFile::writeAll($stream, (string) $writer->flush(true));

            return $count;
        });
    }

    public static function sanitizeName(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $name) ?? '_';
        if ($clean === '' || preg_match('/^[A-Za-z_]/', $clean) !== 1) {
            $clean = '_' . $clean;
        }
        // Names starting with "xml" (any case) are reserved by the XML specification.
        if (stripos($clean, 'xml') === 0) {
            $clean = '_' . $clean;
        }

        return $clean;
    }

    private function writeValue(XMLWriter $writer, string $name, mixed $value, int $depth): void
    {
        $element = self::sanitizeName($name);
        $writer->startElement($element);
        if ($element !== $name) {
            $writer->writeAttribute('name', self::text($name));
        }

        if ($depth >= self::MAX_DEPTH) {
            $writer->writeAttribute('truncated', 'true');
            $writer->endElement();

            return;
        }

        if (is_object($value)) {
            $decoded = json_decode((string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), true);
            $value = is_array($decoded) ? $decoded : (string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        if (is_array($value)) {
            $isList = array_is_list($value);
            foreach ($value as $key => $child) {
                $this->writeValue($writer, $isList ? 'item' : (string) $key, $child, $depth + 1);
            }
        } elseif ($value === null) {
            $writer->writeAttribute('nil', 'true');
        } elseif (is_bool($value)) {
            $writer->text($value ? 'true' : 'false');
        } elseif (is_scalar($value)) {
            $writer->text(self::text((string) $value));
        }

        $writer->endElement();
    }

    private static function text(string $value): string
    {
        $value = \HElturkey\WebTrawler\Support\Text::scrubUtf8($value);

        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
    }
}
