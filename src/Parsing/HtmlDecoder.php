<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Parsing;

use HElturkey\WebTrawler\Support\Text;
use ValueError;

/**
 * Converts raw HTTP bodies to UTF-8 following the usual precedence:
 * byte-order mark, HTTP Content-Type charset, <meta> declaration, then
 * UTF-8 if the bytes are valid, otherwise windows-1252 (the HTML default).
 *
 * @internal
 */
final class HtmlDecoder
{
    private const SNIFF_BYTES = 4096;

    /** @var array<string, string> */
    private const ALIASES = [
        'utf8' => 'UTF-8', 'latin1' => 'WINDOWS-1252', 'latin-1' => 'WINDOWS-1252', 'l1' => 'WINDOWS-1252',
        'iso-8859-1' => 'WINDOWS-1252', 'iso8859-1' => 'WINDOWS-1252', 'ascii' => 'WINDOWS-1252',
        'us-ascii' => 'WINDOWS-1252', 'iso-8859-9' => 'WINDOWS-1254', 'tis-620' => 'WINDOWS-874',
        'gb2312' => 'GBK', 'x-gbk' => 'GBK', 'ks_c_5601-1987' => 'EUC-KR', 'x-sjis' => 'SHIFT_JIS',
        'sjis' => 'SHIFT_JIS', 'shift-jis' => 'SHIFT_JIS', 'x-user-defined' => 'WINDOWS-1252',
    ];

    /**
     * Decode with a caller-chosen charset, ignoring the HTTP header and <meta>.
     *
     * @throws \InvalidArgumentException when the charset is not supported by mbstring/iconv
     */
    public static function decodeAs(string $body, string $charset): string
    {
        $label = strtolower(trim($charset));
        $charset = self::ALIASES[$label] ?? strtoupper($label);

        if ($charset === 'UTF-8') {
            return Text::scrubUtf8(str_starts_with($body, "\xEF\xBB\xBF") ? substr($body, 3) : $body);
        }

        $converted = $body === '' ? '' : self::convert($body, $charset);
        if ($converted === null) {
            throw new \InvalidArgumentException(sprintf('Unsupported encoding "%s". Use a charset known to mbstring or iconv.', $charset));
        }

        return $converted;
    }

    public static function decode(string $body, ?string $contentType = null): string
    {
        if ($body === '') {
            return '';
        }

        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            return Text::scrubUtf8(substr($body, 3));
        }
        if (str_starts_with($body, "\xFF\xFE") || str_starts_with($body, "\xFE\xFF")) {
            $converted = self::convert(substr($body, 2), str_starts_with($body, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');

            return $converted === null ? Text::scrubUtf8($body) : $converted;
        }

        $charset = self::declaredCharset($body, $contentType);
        if ($charset === null) {
            $charset = Text::isUtf8($body) ? 'UTF-8' : 'WINDOWS-1252';
        }

        if ($charset === 'UTF-8') {
            return Text::scrubUtf8($body);
        }

        return self::convert($body, $charset) ?? Text::scrubUtf8($body);
    }

    /**
     * Prepare a string that is already UTF-8 for libxml: scrub invalid bytes and
     * neutralize any <meta charset> that would make libxml re-decode it.
     */
    public static function normalizeUtf8(string $html): string
    {
        $html = Text::scrubUtf8($html);
        $head = substr($html, 0, self::SNIFF_BYTES);
        $rewritten = preg_replace('/(<meta\s[^>]*?charset\s*=\s*["\']?\s*)[A-Za-z0-9_\-:.]+/i', '${1}utf-8', $head, 1);
        if ($rewritten === null || $rewritten === $head) {
            return $html;
        }

        // The cut at SNIFF_BYTES may have split a multibyte character in $head; only
        // the rewritten prefix region changed, so re-attach the untouched remainder.
        return $rewritten . substr($html, self::SNIFF_BYTES);
    }

    private static function declaredCharset(string $body, ?string $contentType): ?string
    {
        $label = null;
        if ($contentType !== null && preg_match('/charset\s*=\s*["\']?\s*([A-Za-z0-9_\-:.]+)/i', $contentType, $match) === 1) {
            $label = $match[1];
        }
        if ($label === null && preg_match('/<meta\s[^>]*?charset\s*=\s*["\']?\s*([A-Za-z0-9_\-:.]+)/i', substr($body, 0, self::SNIFF_BYTES), $match) === 1) {
            $label = $match[1];
        }
        if ($label === null) {
            return null;
        }

        $label = strtolower($label);

        return self::ALIASES[$label] ?? strtoupper($label);
    }

    private static function convert(string $body, string $charset): ?string
    {
        if (function_exists('mb_convert_encoding')) {
            try {
                $converted = mb_convert_encoding($body, 'UTF-8', $charset);
                if (is_string($converted)) {
                    return Text::scrubUtf8($converted);
                }
            } catch (ValueError) {
                // Encoding unknown to mbstring (for example WINDOWS-1256); try iconv.
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
            if (is_string($converted)) {
                return Text::scrubUtf8($converted);
            }
        }

        return null;
    }
}
