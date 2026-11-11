<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Contracts;

use HElturkey\WebTrawler\Http\FetchResponse;

/**
 * Replaceable HTTP transport. Implementations may use Guzzle, curl-impersonate,
 * php-rnet, or another PSR-compatible/native transport.
 */
interface HttpTransport
{
    /** @param array<string, mixed> $options */
    public function send(string $method, string $url, array $options = []): FetchResponse;
}
