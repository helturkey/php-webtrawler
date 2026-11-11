<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;

/** Origin comparison that includes scheme, host and effective port. */
final class UrlOrigin
{
    public static function same(string $first, string $second): bool
    {
        try {
            $a = new Uri($first);
            $b = new Uri($second);
        } catch (InvalidArgumentException) {
            return false;
        }

        return strtolower($a->getScheme()) === strtolower($b->getScheme())
            && strtolower(rtrim($a->getHost(), '.')) === strtolower(rtrim($b->getHost(), '.'))
            && self::effectivePort($a) === self::effectivePort($b);
    }

    private static function effectivePort(Uri $uri): ?int
    {
        if ($uri->getPort() !== null) {
            return $uri->getPort();
        }

        return match (strtolower($uri->getScheme())) {
            'http', 'ws' => 80,
            'https', 'wss' => 443,
            default => null,
        };
    }
}
