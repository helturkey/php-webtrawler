<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Export;

use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;
use Traversable;
use XMLWriter;

/** Streams items to a well-formed XML 1.0 document. */
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
            if (!$writer->openMemory()) {
                throw new RuntimeException('Unable to initialize XML writer.');
            }
            $writer->setIndent($pretty);
            $writer->setIndentString('  ');
            $this->assertWrite($writer->startDocument('1.0', 'UTF-8'));
            $this->assertWrite($writer->startElement($rootElement));
            AtomicFile::writeAll($stream, $this->flush($writer));

            $count = 0;
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new RuntimeException('XML exporter items must be arrays.');
                }
                $this->writeValue($writer, $itemElement, $item, 0);
                AtomicFile::writeAll($stream, $this->flush($writer));
                $count++;
            }

            $this->assertWrite($writer->endElement());
            $this->assertWrite($writer->endDocument());
            AtomicFile::writeAll($stream, $this->flush($writer));

            return $count;
        });
    }

    public static function sanitizeName(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $name) ?? '_';
        if ($clean === '' || preg_match('/^[A-Za-z_]/', $clean) !== 1) {
            $clean = '_' . $clean;
        }
        if (stripos($clean, 'xml') === 0) {
            $clean = '_' . $clean;
        }

        return $clean;
    }

    private function writeValue(XMLWriter $writer, string $name, mixed $value, int $depth): void
    {
        $element = self::sanitizeName($name);
        $this->assertWrite($writer->startElement($element));
        if ($element !== $name) {
            $this->assertWrite($writer->writeAttribute('name', self::text($name)));
        }

        if ($depth >= self::MAX_DEPTH) {
            $this->assertWrite($writer->writeAttribute('truncated', 'true'));
            $this->assertWrite($writer->endElement());

            return;
        }

        if ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if ($value instanceof Traversable) {
            foreach ($value as $key => $child) {
                $this->writeValue($writer, is_int($key) ? 'item' : (string) $key, $child, $depth + 1);
            }
            $this->assertWrite($writer->endElement());

            return;
        } elseif (is_object($value)) {
            // Public properties match json_encode's default object visibility,
            // without serializing and decoding the entire object graph.
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            $isList = array_is_list($value);
            foreach ($value as $key => $child) {
                $this->writeValue($writer, $isList ? 'item' : (string) $key, $child, $depth + 1);
            }
        } elseif ($value === null) {
            $this->assertWrite($writer->writeAttribute('nil', 'true'));
        } elseif (is_bool($value)) {
            $this->assertWrite($writer->text($value ? 'true' : 'false'));
        } elseif (is_scalar($value)) {
            $this->assertWrite($writer->text(self::text((string) $value)));
        }

        $this->assertWrite($writer->endElement());
    }

    private function flush(XMLWriter $writer): string
    {
        $output = $writer->flush(true);
        if (!is_string($output)) {
            throw new RuntimeException('Unable to flush XML writer buffer.');
        }

        return $output;
    }

    private function assertWrite(bool $success): void
    {
        if (!$success) {
            throw new RuntimeException('Unable to write XML export output.');
        }
    }

    private static function text(string $value): string
    {
        $value = \HElturkey\WebTrawler\Support\Text::scrubUtf8($value);

        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
    }
}
