<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Fetching;

use HElturkey\WebTrawler\Concurrency\Pending;
use HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface;
use HElturkey\WebTrawler\Contracts\Http\CancellableTransportInterface;
use HElturkey\WebTrawler\Contracts\Http\TransportInterface;
use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Policy\RequestPolicy;
use HElturkey\WebTrawler\Response\FetchResponse;
use HElturkey\WebTrawler\Retry\RetryPolicy;
use HElturkey\WebTrawler\Retry\RetryingTransport;

/** Executes requests through a transport; security decisions live in RequestPolicy. */
final readonly class Downloader
{
    public function __construct(
        private TransportInterface $transport,
        private RequestPolicy $policy,
        private ?RetryPolicy $defaultRetryPolicy = null,
    ) {
    }

    public function policy(): RequestPolicy
    {
        return $this->policy;
    }

    public function defaultOptions(): FetchOptions
    {
        return $this->policy->defaultOptions();
    }

    public function defaultRetryPolicy(): ?RetryPolicy
    {
        return $this->defaultRetryPolicy;
    }

    public function resolveOptions(?FetchOptions $options): FetchOptions
    {
        return $this->policy->resolveOptions($options);
    }

    public function download(string $url, ?FetchOptions $options = null, string $method = 'GET', bool $applyDefaultRetries = true): FetchResponse
    {
        $options = $this->resolveOptions($options);
        $url = $this->policy->prepareUrl($url, $options);
        $transport = $applyDefaultRetries && $this->defaultRetryPolicy !== null
            ? new RetryingTransport($this->transport, $this->defaultRetryPolicy)
            : $this->transport;

        return $this->policy->verify($transport->send($method, $url, $options->toTransportOptions()), $url, $options);
    }

    public function supportsAsync(): bool
    {
        return $this->transport instanceof AsyncTransportInterface && $this->transport->supportsAsync();
    }

    public function downloadAsync(string $url, ?FetchOptions $options = null, string $method = 'GET'): Pending
    {
        if (!$this->transport instanceof AsyncTransportInterface || !$this->transport->supportsAsync()) {
            throw new \LogicException('The configured transport does not support concurrent requests.');
        }
        $options = $this->resolveOptions($options);
        $url = $this->policy->prepareUrl($url, $options);

        return $this->transport->sendAsync($method, $url, $options->toTransportOptions())
            ->then(fn (mixed $response): FetchResponse => $this->policy->verify(
                $response instanceof FetchResponse ? $response : throw new \UnexpectedValueException('An async transport must resolve to a FetchResponse.'),
                $url,
                $options,
            ));
    }

    public function advance(?float $timeoutSeconds = null): void
    {
        if ($this->transport instanceof AsyncTransportInterface) {
            $this->transport->tick($timeoutSeconds);
        }
    }

    public function cancelAll(): void
    {
        if ($this->transport instanceof CancellableTransportInterface) {
            $this->transport->cancelAll();
        }
    }
}
