<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Exception;

use HElturkey\WebTrawler\Support\UrlSanitizer;

/** The server answered with a status the caller does not accept. */
final class HttpStatusException extends ScraperException
{
    /** The URL with credentials and secret parameters removed. */
    public readonly string $url;

    public function __construct(public readonly int $status, string $url)
    {
        $this->url = UrlSanitizer::sanitize($url);
        parent::__construct(sprintf('Unexpected HTTP status %d for %s.', $status, $this->url));
    }
}
