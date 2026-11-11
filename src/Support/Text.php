<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Support;

/**
 * Small, dependency-free UTF-8 helpers. They deliberately avoid hard-requiring
 * ext-mbstring and never split a multibyte sequence.
 *
 * @internal
 */
final class Text
{
    public static function isUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    /** Replace invalid UTF-8 sequences so the result is always valid UTF-8. */
    public static function scrubUtf8(string $value): string
    {
        if (self::isUtf8($value)) {
            return $value;
        }

        if (function_exists('mb_scrub')) {
            return mb_scrub($value, 'UTF-8');
        }

        if (function_exists('iconv')) {
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if (is_string($clean) && self::isUtf8($clean)) {
                return $clean;
            }
        }

        return preg_replace('/[\x80-\xFF]/', '?', $value) ?? '';
    }

    /** Cut to at most $maxBytes bytes without leaving a partial UTF-8 sequence at the end. */
    public static function truncateBytes(string $value, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $cut = substr($value, 0, $maxBytes);
        $length = strlen($cut);
        $index = $length - 1;
        $continuations = 0;
        while ($index >= 0 && $continuations < 3 && (ord($cut[$index]) & 0xC0) === 0x80) {
            $index--;
            $continuations++;
        }

        if ($index >= 0) {
            $lead = ord($cut[$index]);
            $expected = $lead >= 0xF0 ? 4 : ($lead >= 0xE0 ? 3 : ($lead >= 0xC0 ? 2 : 1));
            if ($expected > $length - $index) {
                $cut = substr($cut, 0, $index);
            }
        }

        return $cut;
    }

    /** Cut to at most $maxChars Unicode code points (no PCRE quantifier limits, no mbstring needed). */
    public static function truncateChars(string $value, int $maxChars): string
    {
        if ($maxChars <= 0) {
            return '';
        }
        // A UTF-8 code point is at least one byte, so short strings cannot exceed the limit.
        if (strlen($value) <= $maxChars) {
            return $value;
        }

        $parts = preg_split('//u', $value, $maxChars + 1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            // Invalid UTF-8: stay total and byte-safe.
            return self::truncateBytes($value, $maxChars);
        }

        return count($parts) <= $maxChars ? $value : implode('', array_slice($parts, 0, $maxChars));
    }

    /** Number of Unicode code points (falls back to bytes for invalid UTF-8); needs no mbstring. */
    public static function lengthChars(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        $count = preg_match_all('/./su', $value);

        return $count === false ? strlen($value) : $count;
    }

    public static function normalizeWhitespace(string $value): string
    {
        $trimmed = trim($value);

        return preg_replace('/[\p{Z}\s]+/u', ' ', $trimmed) ?? $trimmed;
    }
}
