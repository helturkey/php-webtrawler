<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;

/**
 * Validates and canonicalizes request URLs.
 *
 * Accepts internationalized URLs (non-ASCII hosts are converted to punycode,
 * non-ASCII paths and queries are percent-encoded) and rejects everything
 * that is not a well-formed http(s) URL with a host.
 */
final class UrlNormalizer
{
    public static function normalize(string $url): string
    {
        $candidate = trim($url);
        if ($candidate === '' || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            throw self::invalid($url);
        }

        try {
            $uri = new Uri($candidate);
        } catch (InvalidArgumentException) {
            throw self::invalid($url);
        }

        if (!in_array(strtolower($uri->getScheme()), ['http', 'https'], true) || $uri->getHost() === '') {
            throw self::invalid($url);
        }

        $host = $uri->getHost();
        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            if (!function_exists('idn_to_ascii')) {
                throw new InvalidArgumentException(sprintf('Internationalized host names require ext-intl: %s', $url));
            }
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false || $ascii === '') {
                throw self::invalid($url);
            }
            $host = $ascii;
            $uri = $uri->withHost($host);
        }

        $isBracketedIpv6 = str_starts_with($host, '[') && str_ends_with($host, ']')
            && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (!$isBracketedIpv6 && preg_match('/^[A-Za-z0-9._~%-]+$/', $host) !== 1) {
            throw self::invalid($url);
        }

        return (string) $uri;
    }

    private static function invalid(string $url): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('Only valid HTTP and HTTPS URLs are supported: %s', $url));
    }
}
