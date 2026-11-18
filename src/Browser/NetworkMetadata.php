<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Browser;

use HElturkey\WebTrawler\Support\Text;
use HElturkey\WebTrawler\Support\UrlSanitizer;

/** Helpers for safe, bounded browser-network metadata. */
final class NetworkMetadata
{
    /** @var list<string> */
    private const SENSITIVE_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'api-key',
        'x-auth-token',
        'x-access-token',
        'x-csrf-token',
        'x-xsrf-token',
        'x-amz-security-token',
        'x-goog-api-key',
        'authentication-info',
        'www-authenticate',
    ];

    /** @param array<array-key, mixed> $headers
     *  @return array<string, string>
     */
    public static function sanitizeHeaders(array $headers): array
    {
        $safe = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || (!is_string($value) && !is_numeric($value))) {
                continue;
            }

            $normalizedName = strtolower(trim($name));
            if (in_array($normalizedName, self::SENSITIVE_HEADERS, true)) {
                $safe[$name] = '[REDACTED]';
            } elseif (in_array($normalizedName, ['location', 'referer', 'referrer'], true)) {
                $safe[$name] = self::truncate(self::sanitizeUrl((string) $value), 8192);
            } else {
                $safe[$name] = self::truncate((string) $value, 8192);
            }
        }

        return $safe;
    }

    public static function sanitizeUrl(string $url): string
    {
        return UrlSanitizer::sanitize($url);
    }

    /**
     * Represent a captured body safely. Valid UTF-8 stays text; anything else is
     * base64-encoded so the record is always JSON-encodable and lossless.
     *
     * @return array{body: string, encoding: string, truncated: bool}
     */
    public static function encodeBody(string $body, int $maximumBytes): array
    {
        $truncated = strlen($body) > $maximumBytes;
        if (Text::isUtf8($body)) {
            return ['body' => Text::truncateBytes($body, $maximumBytes), 'encoding' => 'utf-8', 'truncated' => $truncated];
        }

        return ['body' => base64_encode(substr($body, 0, max(0, $maximumBytes))), 'encoding' => 'base64', 'truncated' => $truncated];
    }

    public static function truncate(string $value, int $maximumBytes): string
    {
        if ($maximumBytes < 0) {
            return $value;
        }

        // The result is always valid UTF-8: invalid input is scrubbed, and a cut never splits a sequence.
        return Text::truncateBytes(Text::scrubUtf8($value), $maximumBytes);
    }
}
