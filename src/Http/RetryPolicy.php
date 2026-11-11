<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

use HElturkey\WebTrawler\Contracts\TransientFailure;
use Psr\Http\Client\NetworkExceptionInterface;
use Throwable;

/** Pure decision logic shared by RetryingTransport and the spider runner. */
final readonly class RetryPolicy
{
    public const JITTER_NONE = 'none';
    public const JITTER_EQUAL = 'equal';
    public const JITTER_FULL = 'full';

    /**
     * @param float $multiplier 2.0 = exponential, 1.0 = constant delay.
     * @param list<int> $retryStatuses
     * @param list<string> $retryMethods Methods that are safe to repeat.
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelayMs = 500,
        public int $maxDelayMs = 30_000,
        public float $multiplier = 2.0,
        public string $jitter = self::JITTER_EQUAL,
        public array $retryStatuses = [408, 425, 429, 500, 502, 503, 504],
        public array $retryMethods = ['GET', 'HEAD', 'OPTIONS'],
        public bool $respectRetryAfter = true,
    ) {
        if ($maxAttempts < 1 || $baseDelayMs < 0 || $maxDelayMs < 0 || !is_finite($multiplier) || $multiplier < 1.0) {
            throw new \InvalidArgumentException('Retry attempts must be positive, delays non-negative and the multiplier at least 1.');
        }
        if (!in_array($jitter, [self::JITTER_NONE, self::JITTER_EQUAL, self::JITTER_FULL], true)) {
            throw new \InvalidArgumentException('Unknown jitter mode: ' . $jitter);
        }
    }

    public static function none(): self
    {
        return new self(maxAttempts: 1);
    }

    public function retriesEnabled(): bool
    {
        return $this->maxAttempts > 1;
    }

    public function allowsMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->retryMethods, true);
    }

    public function shouldRetryStatus(int $status): bool
    {
        return in_array($status, $this->retryStatuses, true);
    }

    public function shouldRetryException(Throwable $exception): bool
    {
        return $exception instanceof NetworkExceptionInterface || $exception instanceof TransientFailure;
    }

    /** @param int $failedAttempt 1-based number of the attempt that just failed */
    public function delayMs(int $failedAttempt, ?string $retryAfter = null): int
    {
        $delay = (int) min((float) $this->maxDelayMs, $this->baseDelayMs * ($this->multiplier ** max(0, $failedAttempt - 1)));
        if ($delay > 0) {
            $delay = match ($this->jitter) {
                self::JITTER_FULL => random_int(0, $delay),
                self::JITTER_EQUAL => random_int(intdiv($delay, 2), $delay),
                default => $delay,
            };
        }

        if ($this->respectRetryAfter) {
            $requested = self::parseRetryAfter($retryAfter);
            if ($requested !== null) {
                $delay = max($delay, $requested);
            }
        }

        return min($delay, $this->maxDelayMs);
    }

    public static function parseRetryAfter(?string $value): ?int
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            $seconds = filter_var($value, FILTER_VALIDATE_INT);
            if ($seconds === false || $seconds > intdiv(PHP_INT_MAX, 1000)) {
                return PHP_INT_MAX;
            }

            return $seconds * 1000;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        $seconds = max(0, $timestamp - time());

        return $seconds > intdiv(PHP_INT_MAX, 1000) ? PHP_INT_MAX : $seconds * 1000;
    }
}
