<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Support;

/**
 * Removes secrets from URLs before they are stored or logged: userinfo, and the
 * values of sensitive parameters in both the query and the fragment. Parameter
 * names are percent-decoded first, so %61ccess_token is treated as access_token.
 */
final class UrlSanitizer
{
    public const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'access_token', 'accesstoken', 'api_key', 'apikey', 'auth', 'authorization', 'client_secret', 'code',
        'credential', 'id_token', 'idtoken', 'jwt', 'key', 'passwd', 'password', 'pwd', 'refresh_token', 'secret',
        'session', 'session_id', 'sessionid', 'sid', 'sig', 'signature', 'token', 'x-amz-signature', 'x-amz-security-token',
    ];

    public static function sanitize(string $url): string
    {
        // Userinfo: scheme://user:pass@host -> scheme://host; also handle protocol-relative //user:pass@host.
        $url = preg_replace('~^((?:[a-z][a-z0-9+.\-]*:)?//)[^/?#@]*@~i', '$1', $url) ?? $url;

        $fragment = null;
        $hashPosition = strpos($url, '#');
        if ($hashPosition !== false) {
            $fragment = substr($url, $hashPosition + 1);
            $url = substr($url, 0, $hashPosition);
        }

        $queryPosition = strpos($url, '?');
        if ($queryPosition !== false) {
            $url = substr($url, 0, $queryPosition + 1) . self::redactPairs(substr($url, $queryPosition + 1));
        }

        if ($fragment !== null) {
            // SPA routers put "#/route?token=..." or "#access_token=..." in the fragment.
            $questionMark = strpos($fragment, '?');
            if ($questionMark !== false) {
                $fragment = substr($fragment, 0, $questionMark + 1) . self::redactPairs(substr($fragment, $questionMark + 1));
            } elseif (str_contains($fragment, '=')) {
                $fragment = self::redactPairs($fragment);
            }
            $url .= '#' . $fragment;
        }

        return $url;
    }

    /** Redact every URL inside free text such as an exception message. */
    public static function sanitizeText(string $text): string
    {
        return (string) preg_replace_callback(
            '~(?<![:/])(?:[a-z][a-z0-9+.\-]*:)?//[^\s)\'"<>]+~i',
            static fn (array $match): string => self::sanitize($match[0]),
            $text,
        );
    }

    public static function isSensitiveKey(string $key): bool
    {
        $decoded = strtolower(trim(rawurldecode(str_replace('+', ' ', $key))));
        if ($decoded === '') {
            return false;
        }
        if (in_array($decoded, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        $compact = preg_replace('/[^a-z0-9]/', '', $decoded) ?? $decoded;
        foreach (['token', 'secret', 'password', 'passwd', 'apikey', 'credential', 'signature', 'sessionid'] as $needle) {
            if (str_contains($compact, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function redactPairs(string $pairs): string
    {
        $parts = preg_split('/([&;])/', $pairs, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $pairs;
        }

        foreach ($parts as $index => $part) {
            if ($part === '&' || $part === ';' || $part === '') {
                continue;
            }
            $equals = strpos($part, '=');
            $name = $equals === false ? $part : substr($part, 0, $equals);
            if ($equals !== false && self::isSensitiveKey($name)) {
                $parts[$index] = $name . '=' . self::REDACTED;
            }
        }

        return implode('', $parts);
    }
}
