<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

/**
 * Adaptive per-host politeness delay, modelled on Scrapy's AutoThrottle: the delay tracks
 * latency / targetConcurrency, smoothed against the previous delay. Slow or failing responses
 * can raise the delay but never lower it, and the result is clamped to [minDelayMs, maxDelayMs].
 */
final class AutoThrottle
{
    /** @var array<string, float> */
    private array $delays = [];

    public function __construct(
        private readonly float $targetConcurrency = 1.0,
        private readonly int $startDelayMs = 1000,
        private readonly int $minDelayMs = 0,
        private readonly int $maxDelayMs = 60_000,
    ) {
        if (!is_finite($targetConcurrency) || $targetConcurrency <= 0.0 || $startDelayMs < 0 || $minDelayMs < 0 || $maxDelayMs < $minDelayMs) {
            throw new \InvalidArgumentException('AutoThrottle needs a positive target concurrency and 0 <= min <= max delays.');
        }
    }

    public function delayMs(string $host): int
    {
        return (int) round($this->delays[strtolower($host)] ?? $this->clamp((float) $this->startDelayMs));
    }

    public function record(string $host, float $latencyMs, int $status): void
    {
        if (!is_finite($latencyMs) || $latencyMs < 0.0) {
            throw new \InvalidArgumentException('AutoThrottle latency must be a finite non-negative number of milliseconds.');
        }

        $host = strtolower($host);
        $previous = $this->delays[$host] ?? $this->clamp((float) $this->startDelayMs);
        $next = $this->clamp(($previous + $latencyMs / $this->targetConcurrency) / 2);

        // Errors must not make us faster.
        if (($status < 200 || $status >= 300) && $next < $previous) {
            return;
        }
        $this->delays[$host] = $next;
    }

    private function clamp(float $value): float
    {
        return max((float) $this->minDelayMs, min((float) $this->maxDelayMs, $value));
    }
}
