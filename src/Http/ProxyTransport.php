<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

use GuzzleHttp\Promise\PromiseInterface;
use HElturkey\WebTrawler\Contracts\AsyncHttpTransport;
use HElturkey\WebTrawler\Contracts\HttpTransport;
use HElturkey\WebTrawler\Exception\ProxyPoolExhaustedException;
use Throwable;

/**
 * Routes requests through a proxy from a ProxyPool and feeds the outcome back into the pool.
 * By default, an explicit request proxy may override the pool for backwards compatibility; set
 * $allowExplicitProxyOverride to false when the pool is an enforced network route (as in the Laravel
 * integration). When the pool is exhausted the request fails unless $allowDirectFallback is set.
 */
final class ProxyTransport implements AsyncHttpTransport
{
    /** @param list<int> $failureStatuses Response statuses that count as a proxy failure. */
    public function __construct(
        private readonly HttpTransport $inner,
        private readonly ProxyPool $pool,
        private readonly bool $allowDirectFallback = false,
        private readonly array $failureStatuses = [407],
        private readonly bool $allowExplicitProxyOverride = true,
    ) {
    }

    public function supportsAsync(): bool
    {
        return $this->inner instanceof AsyncHttpTransport && $this->inner->supportsAsync();
    }

    public function send(string $method, string $url, array $options = []): FetchResponse
    {
        [$options, $proxy] = $this->assign($options);
        try {
            $response = $this->inner->send($method, $url, $options);
        } catch (Throwable $exception) {
            $this->report($proxy, false);
            throw $exception;
        }
        $this->report($proxy, !in_array($response->status, $this->failureStatuses, true));

        return $response;
    }

    public function sendAsync(string $method, string $url, array $options = []): PromiseInterface
    {
        if (!$this->inner instanceof AsyncHttpTransport || !$this->inner->supportsAsync()) {
            throw new \LogicException('The wrapped transport does not support concurrent requests.');
        }
        [$options, $proxy] = $this->assign($options);
        try {
            $promise = $this->inner->sendAsync($method, $url, $options);
        } catch (Throwable $exception) {
            $this->report($proxy, false);
            throw $exception;
        }

        return $promise->then(
            function (FetchResponse $response) use ($proxy): FetchResponse {
                $this->report($proxy, !in_array($response->status, $this->failureStatuses, true));

                return $response;
            },
            function (mixed $reason) use ($proxy): never {
                $this->report($proxy, false);
                throw $reason instanceof Throwable ? $reason : new \RuntimeException('Request failed.');
            },
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function assign(array $options): array
    {
        if (isset($options['proxy'])) {
            if (!$this->allowExplicitProxyOverride) {
                throw new \InvalidArgumentException('An explicit request proxy cannot bypass the configured proxy pool.');
            }

            return [$options, null];
        }

        $proxy = $this->pool->acquire();
        if ($proxy === null) {
            if ($this->allowDirectFallback) {
                return [$options, null];
            }
            throw new ProxyPoolExhaustedException('All proxies are cooling down after repeated failures.');
        }
        $options['proxy'] = $proxy;

        return [$options, $proxy];
    }

    private function report(?string $proxy, bool $success): void
    {
        if ($proxy === null) {
            return;
        }
        $success ? $this->pool->reportSuccess($proxy) : $this->pool->reportFailure($proxy);
    }
}
