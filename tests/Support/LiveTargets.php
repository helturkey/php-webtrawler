<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Support;

/** Public targets shared by opt-in integration tests; these mirror the package's existing examples. */
final class LiveTargets
{
    public const QUOTES_URL = 'https://quotes.toscrape.com/';
    public const QUOTES_HOST = 'quotes.toscrape.com';

    public const BOOKS_URL = 'https://books.toscrape.com/';
    public const BOOKS_HOST = 'books.toscrape.com';

    /** The Books page loads its legacy jQuery asset from Google's AJAX CDN. */
    public const BOOKS_BROWSER_HOSTS = [self::BOOKS_HOST, 'ajax.googleapis.com'];

    /** @return list<string> */
    public static function hosts(): array
    {
        return [self::QUOTES_HOST, self::BOOKS_HOST];
    }
}
