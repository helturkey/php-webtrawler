<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Contracts;

use GuzzleHttp\Promise\PromiseInterface;
use HElturkey\WebTrawler\Http\FetchResponse;

/** A transport that can run several requests concurrently. */
interface AsyncHttpTransport extends HttpTransport
{
    /** Wrappers return false when the transport they wrap cannot run concurrently. */
    public function supportsAsync(): bool;

    /**
     * @param array<string, mixed> $options
     * @return PromiseInterface Resolves to a FetchResponse; rejects with the same exceptions send() would throw.
     */
    public function sendAsync(string $method, string $url, array $options = []): PromiseInterface;
}
