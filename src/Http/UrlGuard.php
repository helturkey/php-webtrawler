<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

use GuzzleHttp\Psr7\Uri;
use HElturkey\WebTrawler\Exception\UnsafeUrlException;

/**
 * Opt-in SSRF mitigation: rejects URLs whose host is, or resolves to, a
 * loopback, private, link-local, reserved or otherwise non-global address.
 *
 * Limits: a hostname is resolved here and again by the HTTP client, so a
 * hostile DNS server can still rebind between the two lookups. For hostile
 * input run the crawler in a network-isolated environment as well.
 */
final class UrlGuard
{
    /** @throws UnsafeUrlException */
    public static function assertPublic(string $url): void
    {
        try {
            $host = strtolower((new Uri($url))->getHost());
        } catch (\InvalidArgumentException) {
            throw new UnsafeUrlException(sprintf('Unparseable URL: %s', $url));
        }
        if ($host === '') {
            throw new UnsafeUrlException(sprintf('URL has no host: %s', $url));
        }

        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            if (!self::isPublicIp($literal)) {
                throw new UnsafeUrlException(sprintf('Refusing to connect to non-public address %s.', $literal));
            }

            return;
        }

        // Forms such as 2130706433, 0x7f.1 or 017700000001 are interpreted as IPv4
        // by libc/curl but are not valid dotted quads for filter_var().
        if (preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/i', $host) === 1) {
            throw new UnsafeUrlException(sprintf('Refusing ambiguous numeric host %s.', $host));
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            throw new UnsafeUrlException(sprintf('Refusing to connect to local host name %s.', $host));
        }

        $addresses = self::resolve($host);
        if ($addresses === []) {
            // Fail closed: a name we cannot resolve cannot be verified as public.
            throw new UnsafeUrlException(sprintf('Host %s did not resolve to any address; refusing to connect.', $host));
        }

        foreach ($addresses as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new UnsafeUrlException(sprintf('Host %s resolves to non-public address %s.', $host, $ip));
            }
        }
    }

    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::/96) embed an IPv4 address.
            $prefix = substr($packed, 0, 12);
            if ($prefix === str_repeat("\x00", 10) . "\xff\xff" || $prefix === "\x00\x64\xff\x9b" . str_repeat("\x00", 8)) {
                $embedded = inet_ntop(substr($packed, 12));

                return is_string($embedded) && self::isPublicIp($embedded);
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $addresses = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $addresses = $v4;
        }
        $v6 = function_exists('dns_get_record') ? @dns_get_record($host, DNS_AAAA) : false;
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (is_string($record['ipv6'] ?? null)) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
