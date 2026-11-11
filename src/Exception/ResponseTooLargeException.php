<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Exception;

use HElturkey\WebTrawler\Support\UrlSanitizer;

/** The response body exceeded FetchOptions::$maxBodyBytes. */
final class ResponseTooLargeException extends ScraperException
{
    public static function forLimit(int $limit, string $url): self
    {
        return new self(sprintf('Response from %s exceeded the %d byte limit.', UrlSanitizer::sanitize($url), $limit));
    }
}
