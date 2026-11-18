<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Contracts\Http;

use HElturkey\WebTrawler\Concurrency\Pending;
use HElturkey\WebTrawler\Response\FetchResponse;

/**
 * A transport that can keep several requests in flight. The contract does not assume any async library:
 * the transport starts a request with sendAsync() and the caller drives it forward with tick().
 *
 * Implement it with whatever you have (curl-multi, Revolt/Amp fibers, ReactPHP, a worker pool). The spider
 * runner only needs these three methods.
 */
interface AsyncTransportInterface extends TransportInterface
{
    /** Wrappers return false when the transport they wrap cannot run concurrently. */
    public function supportsAsync(): bool;

    /**
     * Start a request and return at once.
     *
     * @param array<string, mixed> $options
     * @return Pending Resolves to a FetchResponse; rejects with the exceptions send() would throw.
     */
    public function sendAsync(string $method, string $url, array $options = []): Pending;

    /**
     * Advance in-flight requests and return as soon as at least one of them settled, nothing is in flight,
     * or about $timeoutSeconds elapsed (a hint: transports that cannot wake early may return later).
     * Returning without a settled request is allowed; the caller simply calls tick() again.
     * Request failures settle their Pending; tick() itself only throws for broken transports.
     */
    public function tick(?float $timeoutSeconds = null): void;
}
