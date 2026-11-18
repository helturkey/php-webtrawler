<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Proxy;

use HElturkey\WebTrawler\Transport\Drivers\AmpTransport;
use HElturkey\WebTrawler\Response\FetchResponse;
use HElturkey\WebTrawler\Concurrency\Pending;
use HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface;
use HElturkey\WebTrawler\Contracts\Http\CancellableTransportInterface;
use HElturkey\WebTrawler\Contracts\Http\TransportInterface;
use HElturkey\WebTrawler\Exception\ProxyPoolExhaustedException;
use HElturkey\WebTrawler\Exception\RequestCancelledException;
use Throwable;

/**
 * Routes requests through a proxy from a ProxyPool and feeds the outcome back into the pool.
 * The pool is an enforced network route by default: a request that carries its own proxy is rejected instead
 * of silently bypassing the pool. Pass $allowExplicitProxyOverride: true only when callers are trusted to choose
 * their own proxy. When the pool is exhausted the request fails unless $allowDirectFallback is set.
 */
final class ProxyTransport implements CancellableTransportInterface
{
    /** @var array<int, Pending> */
    private array $pendingRequests = [];
    private int $nextPendingId = 1;
    /** @param list<int> $failureStatuses Response statuses that count as a proxy failure. */
    public function __construct(
        private readonly TransportInterface $inner,
        private readonly ProxyPool $pool,
        private readonly bool $allowDirectFallback = false,
        private readonly array $failureStatuses = [407],
        private readonly bool $allowExplicitProxyOverride = false,
    ) {
        if ($inner instanceof AmpTransport && !$inner->supportsProxy()) {
            throw new \LogicException('AmpTransport cannot route through a proxy pool: install amphp/http-tunnel (composer require amphp/http-tunnel) or give it a client factory.');
        }
    }

    public function supportsAsync(): bool
    {
        return $this->inner instanceof AsyncTransportInterface && $this->inner->supportsAsync();
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

    public function sendAsync(string $method, string $url, array $options = []): Pending
    {
        if (!$this->inner instanceof AsyncTransportInterface || !$this->inner->supportsAsync()) {
            throw new \LogicException('The wrapped transport does not support concurrent requests.');
        }
        [$options, $proxy] = $this->assign($options);
        try {
            $pending = $this->inner->sendAsync($method, $url, $options);
        } catch (Throwable $exception) {
            $this->report($proxy, false);
            throw $exception;
        }

        $id = $this->nextPendingId++;
        $result = new Pending();
        $this->pendingRequests[$id] = $result;
        $pending->onSettled(function (Pending $settled) use ($id, $proxy, $result): void {
            $entry = $this->pendingRequests[$id] ?? null;
            unset($this->pendingRequests[$id]);
            if ($entry === null) {
                return;
            }

            try {
                $response = $settled->value();
                if ($response instanceof FetchResponse) {
                    $this->report($proxy, !in_array($response->status, $this->failureStatuses, true));
                }
                $result->resolve($response);
            } catch (Throwable $error) {
                if (!$error instanceof RequestCancelledException) {
                    $this->report($proxy, false);
                }
                $result->reject($error);
            }
        });

        return $result;
    }

    public function cancelAll(): void
    {
        $open = $this->pendingRequests;
        $this->pendingRequests = [];
        foreach ($open as $entry) {
            $entry->reject(new RequestCancelledException('The request was cancelled.'));
        }

        // Physical I/O cancellation is delegated when the wrapped transport supports it.
        if ($this->inner instanceof CancellableTransportInterface) {
            $this->inner->cancelAll();
        }
    }

    public function tick(?float $timeoutSeconds = null): void
    {
        if ($this->inner instanceof AsyncTransportInterface) {
            $this->inner->tick($timeoutSeconds);
        }
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
