<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Export;

use RuntimeException;

final class CsvExporter
{
    /**
     * @param iterable<array<string, mixed>> $items
     * @param list<string>|null $headers Supply headers when items have different/dynamic keys.
     * @param bool $utf8Bom Prepend a UTF-8 byte-order mark so Excel opens Arabic/CJK text correctly.
     * @param bool $neutralizeFormulas Prefix string cells starting with = + - @ (or tab/CR) with an
     *                                 apostrophe to prevent CSV/formula injection when opened in a spreadsheet.
     */
    public function export(
        iterable $items,
        string $filePath,
        string $delimiter = ',',
        ?array $headers = null,
        bool $utf8Bom = false,
        bool $neutralizeFormulas = false,
    ): int {
        if (strlen($delimiter) !== 1) {
            throw new \InvalidArgumentException('CSV delimiter must be exactly one byte.');
        }

        return AtomicFile::write($filePath, function ($stream) use ($items, $delimiter, $headers, $utf8Bom, $neutralizeFormulas): int {
            if ($utf8Bom) {
                AtomicFile::writeAll($stream, "\xEF\xBB\xBF");
            }

            $count = 0;
            $headersWritten = false;
            if ($headers !== null) {
                if ($headers !== []) {
                    $this->writeRow($stream, $this->cells($headers, $neutralizeFormulas), $delimiter);
                }
                $headersWritten = true;
            }

            foreach ($items as $row) {
                if (!$headersWritten) {
                    $headers = array_map('strval', array_keys($row));
                    if ($headers !== []) {
                        $this->writeRow($stream, $this->cells($headers, $neutralizeFormulas), $delimiter);
                    }
                    $headersWritten = true;
                }

                $values = [];
                foreach ($headers ?? [] as $header) {
                    $values[] = $row[$header] ?? null;
                }
                $this->writeRow($stream, $this->cells($values, $neutralizeFormulas), $delimiter);
                $count++;
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
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return null;
        }

        $text = (string) $value;
        // Only text can carry a formula; real numbers (-5) must stay untouched.
        if ($neutralizeFormulas && is_string($value) && self::looksLikeFormula($text)) {
            return "'" . $text;
        }

        return $text;
    }

    /**
     * Spreadsheets ignore leading whitespace/control characters (and BOM/zero-width marks), so a
     * formula can hide behind them: " =1+1", "\n=1+1", "\x00@SUM(...)".
     */
    private static function looksLikeFormula(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        // A leading tab or carriage return is always neutralized.
        if ($text[0] === "\t" || $text[0] === "\r") {
            return true;
        }

        $stripped = preg_replace('/^[\x00-\x20\x7F\x{00A0}\x{200B}-\x{200F}\x{2028}\x{2029}\x{FEFF}]+/u', '', $text) ?? $text;

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
