<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

use HElturkey\WebTrawler\Exception\HostNotAllowedException;
use HElturkey\WebTrawler\Support\UrlSanitizer;

/**
 * The single definition of "is this host allowed": an exact match or a subdomain of a listed host.
 * An empty list allows everything. Used before every request and before every redirect hop.
 */
final class HostPolicy
{
    /** @param list<string> $allowedHosts */
    public static function allows(string $url, array $allowedHosts): bool
    {
        if ($allowedHosts === []) {
            return true;
        }

        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        if ($host === '') {
            return false;
        }
        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(trim($allowed, ". \t"));
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.' . $allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $allowedHosts
     * @throws HostNotAllowedException
     */
    public static function assert(string $url, array $allowedHosts): void
    {
        if (!self::allows($url, $allowedHosts)) {
            throw new HostNotAllowedException(sprintf('Host of %s is not in the allow-list.', UrlSanitizer::sanitize($url)));
        }
    }
}
