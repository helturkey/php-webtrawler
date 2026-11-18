<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Export;

use InvalidArgumentException;
use RuntimeException;

final class CsvExporter
{
    /**
     * @param iterable<array<string, mixed>> $items
     * @param list<string>|null $headers Supply headers when items have different/dynamic keys.
     * @param bool $utf8Bom Prepend a UTF-8 byte-order mark for spreadsheet compatibility.
     * @param bool $neutralizeFormulas Prefix formula-like string cells with an apostrophe.
     */
    public function export(
        iterable $items,
        string $filePath,
        string $delimiter = ',',
        ?array $headers = null,
        bool $utf8Bom = false,
        bool $neutralizeFormulas = false,
    ): int {
        if (strlen($delimiter) !== 1 || str_contains('"' . "\r\n\0", $delimiter)) {
            throw new InvalidArgumentException('CSV delimiter must be one byte and cannot be a quote, line break, or NUL.');
        }
        if ($headers !== null) {
            if (!array_is_list($headers)) {
                throw new InvalidArgumentException('CSV headers must be a list of strings.');
            }
            foreach ($headers as $header) {
                if (!is_string($header)) {
                    throw new InvalidArgumentException('CSV headers must contain only strings.');
                }
            }
            if (count(array_unique($headers)) !== count($headers)) {
                throw new InvalidArgumentException('CSV headers must be unique.');
            }
        }

        return AtomicFile::write($filePath, function ($stream) use ($items, $delimiter, $headers, $utf8Bom, $neutralizeFormulas): int {
            if ($utf8Bom) {
                AtomicFile::writeAll($stream, "\xEF\xBB\xBF");
            }

            $count = 0;
            $headersWritten = $headers !== null;
            $pendingEmptyRows = 0;

            if ($headers !== null && $headers !== []) {
                $this->writeRow($stream, $this->cells($headers, $neutralizeFormulas), $delimiter);
            }

            foreach ($items as $row) {
                if (!is_array($row)) {
                    throw new RuntimeException('CSV exporter items must be arrays.');
                }

                if (!$headersWritten) {
                    if ($row === []) {
                        // Wait for the first non-empty row so it can define the
                        // columns. Emit preceding empty rows after the header.
                        $pendingEmptyRows++;
                        $count++;
                        continue;
                    }

                    $headers = array_map('strval', array_keys($row));
                    if ($headers !== []) {
                        $this->writeRow($stream, $this->cells($headers, $neutralizeFormulas), $delimiter);
                    }
                    $headersWritten = true;

                    while ($pendingEmptyRows > 0) {
                        $this->writeRow($stream, [], $delimiter);
                        $pendingEmptyRows--;
                    }
                }

                $values = [];
                foreach ($headers ?? [] as $header) {
                    $values[] = $row[$header] ?? null;
                }
                $this->writeRow($stream, $this->cells($values, $neutralizeFormulas), $delimiter);
                $count++;
            }

            // An all-empty iterable has no discoverable header row; preserve its
            // row count as empty CSV records.
            while ($pendingEmptyRows > 0) {
                $this->writeRow($stream, [], $delimiter);
                $pendingEmptyRows--;
            }

            return $count;
        });
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<string|null>
     */
    private function cells(array $values, bool $neutralizeFormulas): array
    {
        $cells = [];
        foreach ($values as $value) {
            $cells[] = $this->cell($value, $neutralizeFormulas);
        }

        return $cells;
    }

    private function cell(mixed $value, bool $neutralizeFormulas): ?string
    {
        if (is_array($value) || is_object($value)) {
            $text = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } elseif (is_bool($value)) {
            $text = $value ? 'true' : 'false';
        } elseif ($value === null) {
            return null;
        } else {
            $text = (string) $value;
        }

        // Keep actual numeric values unchanged; strings are untrusted text and
        // may be interpreted as formulas by spreadsheet applications.
        if ($neutralizeFormulas && is_string($value) && self::looksLikeFormula($text)) {
            return "'" . $text;
        }

        return $text;
    }

    private static function looksLikeFormula(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        // Strip ASCII controls, Unicode separators, and format characters that
        // spreadsheet applications may ignore before evaluating a formula.
        $stripped = preg_replace('/^[\x00-\x20\x7F]+/', '', $text) ?? $text;
        $stripped = preg_replace('/^[\p{Z}\p{Cf}]+/u', '', $stripped) ?? $stripped;

        return $stripped !== '' && strpbrk($stripped[0], '=+-@') !== false;
    }

    /**
     * @param resource $stream
     * @param list<string|null> $values
     */
    private function writeRow($stream, array $values, string $delimiter): void
    {
        if (fputcsv($stream, $values, $delimiter, '"', '') === false) {
            throw new RuntimeException('Unable to write CSV row.');
        }
    }
}
