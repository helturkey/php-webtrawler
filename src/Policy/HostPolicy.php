<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Policy;

use HElturkey\WebTrawler\Exception\HostNotAllowedException;
use HElturkey\WebTrawler\Support\UrlSanitizer;

/**
 * The shared host allow-list policy: an exact match or a subdomain of a listed host.
 * An empty list allows every host; callers choose the schemes appropriate to their request type.
 */
final class HostPolicy
{
    /**
     * @param list<string> $allowedHosts
     * @param list<string> $allowedSchemes
     */
    public static function allows(string $url, array $allowedHosts, array $allowedSchemes = ['http', 'https']): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        if (!in_array($scheme, $allowedSchemes, true) || $host === '') {
            return false;
        }
        if ($allowedHosts === []) {
            return true;
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
