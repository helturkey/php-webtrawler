<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler;

/** Single source of truth for the library version (used in the default User-Agent). */
final class Version
{
    public const CURRENT = '1.0.0';

    public static function userAgent(): string
    {
        return 'HElturkey-WebTrawler: ' . self::CURRENT;
    }
}
