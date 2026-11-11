<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

use Closure;
use HElturkey\WebTrawler\Contracts\HttpTransport;
use Throwable;

/** Decorator that applies a RetryPolicy to blocking requests. (The spider runner retries on its own, non-blocking.) */
final class RetryingTransport implements HttpTransport
{
    private readonly RetryPolicy $policy;

    /** @var Closure(int): void */
    private readonly Closure $sleeper;

    /** @param (callable(int): void)|null $sleeper Receives milliseconds; defaults to usleep(). */
    public function __construct(private readonly HttpTransport $inner, ?RetryPolicy $policy = null, ?callable $sleeper = null)
    {
        $this->policy = $policy ?? new RetryPolicy();
        $this->sleeper = $sleeper !== null
            ? Closure::fromCallable($sleeper)
            : static function (int $milliseconds): void {
                usleep($milliseconds * 1000);
            };
    }

    public function send(string $method, string $url, array $options = []): FetchResponse
    {
        $retryable = $this->policy->allowsMethod($method);

        for ($attempt = 1; ; $attempt++) {
            $canRetry = $retryable && $attempt < $this->policy->maxAttempts;
            try {
                $response = $this->inner->send($method, $url, $options);
            } catch (Throwable $exception) {
                if (!$canRetry || !$this->policy->shouldRetryException($exception)) {
                    throw $exception;
                }
                ($this->sleeper)($this->policy->delayMs($attempt));
                continue;
            }

            if (!$canRetry || !$this->policy->shouldRetryStatus($response->status)) {
                return $response;
            }

            ($this->sleeper)($this->policy->delayMs($attempt, $response->header('Retry-After')));
        }
    }
}
