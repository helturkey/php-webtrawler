<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

use Closure;

/**
 * Health-aware proxy rotation with a circuit breaker per proxy: after N consecutive failures a proxy
 * is skipped for a cooldown, then probed again (half-open). Credentials embedded in proxy URLs are
 * never included in stats() or exception messages.
 */
final class ProxyPool
{
    public const ROUND_ROBIN = 'round_robin';
    public const RANDOM = 'random';
    public const LEAST_FAILURES = 'least_failures';

    /** @var list<string> */
    private array $proxies;

    /** @var array<string, array{failures: int, total_failures: int, successes: int, blocked_until: int, probe_in_flight: bool}> */
    private array $health = [];

    private int $cursor = 0;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param list<string> $proxies e.g. http://user:pass@host:8080, socks5h://host:1080
     * @param (callable(): int)|null $clock Unix seconds; injectable for tests.
     */
    public function __construct(
        array $proxies,
        private readonly string $strategy = self::ROUND_ROBIN,
        private readonly int $maxConsecutiveFailures = 3,
        private readonly int $cooldownSeconds = 60,
        ?callable $clock = null,
    ) {
        if ($proxies === [] || !array_is_list($proxies) || array_filter($proxies, static fn (mixed $proxy): bool => is_string($proxy) && trim($proxy) !== '') !== $proxies) {
            throw new \InvalidArgumentException('A proxy pool needs a list of non-empty proxy URLs.');
        }
        if (!in_array($strategy, [self::ROUND_ROBIN, self::RANDOM, self::LEAST_FAILURES], true)) {
            throw new \InvalidArgumentException('Unknown proxy strategy: ' . $strategy);
        }
        if ($maxConsecutiveFailures < 1 || $cooldownSeconds < 0) {
            throw new \InvalidArgumentException('Failure threshold must be positive and the cooldown non-negative.');
        }

        $unique = [];
        foreach ($proxies as $proxy) {
            $scheme = strtolower((string) parse_url($proxy, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https', 'socks4', 'socks4a', 'socks5', 'socks5h'], true) || (string) parse_url($proxy, PHP_URL_HOST) === '') {
                throw new \InvalidArgumentException('Invalid proxy URL: ' . self::mask($proxy));
            }
            $unique[$proxy] = true;
        }
        $this->proxies = array_map('strval', array_keys($unique));
        foreach ($this->proxies as $proxy) {
            $this->health[$proxy] = ['failures' => 0, 'total_failures' => 0, 'successes' => 0, 'blocked_until' => 0, 'probe_in_flight' => false];
        }
        $this->clock = $clock !== null ? Closure::fromCallable($clock) : static fn (): int => time();
    }

    /** @return string|null A usable proxy, or null when every proxy is cooling down. */
    public function acquire(): ?string
    {
        $now = ($this->clock)();
        $available = [];
        $halfOpen = [];
        foreach ($this->proxies as $proxy) {
            $state = $this->health[$proxy];
            if ($state['blocked_until'] === 0) {
                $available[] = $proxy;
            } elseif ($state['blocked_until'] <= $now && !$state['probe_in_flight']) {
                $halfOpen[] = $proxy;
            }
        }

        $candidates = [...$available, ...$halfOpen];
        if ($candidates === []) {
            return null;
        }

        $candidate = $this->select($candidates);
        if (in_array($candidate, $halfOpen, true)) {
            // Only one concurrent request may probe a proxy after its cooldown.
            $this->health[$candidate]['probe_in_flight'] = true;
        }

        return $candidate;
    }

    /** @param list<string> $available */
    private function select(array $available): string
    {
        switch ($this->strategy) {
            case self::RANDOM:
                return $available[random_int(0, count($available) - 1)];
            case self::LEAST_FAILURES:
                usort($available, fn (string $a, string $b): int => $this->health[$a]['failures'] <=> $this->health[$b]['failures']);

                return $available[0];
            default:
                $count = count($this->proxies);
                for ($i = 0; $i < $count; $i++) {
                    $candidate = $this->proxies[$this->cursor++ % $count];
                    if (in_array($candidate, $available, true)) {
                        return $candidate;
                    }
                }

                return $available[0];
        }
    }

    public function reportSuccess(string $proxy): void
    {
        if (isset($this->health[$proxy])) {
            $this->health[$proxy]['failures'] = 0;
            $this->health[$proxy]['blocked_until'] = 0;
            $this->health[$proxy]['probe_in_flight'] = false;
            $this->health[$proxy]['successes']++;
        }
    }

    public function reportFailure(string $proxy): void
    {
        if (!isset($this->health[$proxy])) {
            return;
        }
        $this->health[$proxy]['probe_in_flight'] = false;
        $this->health[$proxy]['failures']++;
        $this->health[$proxy]['total_failures']++;
        if ($this->health[$proxy]['failures'] >= $this->maxConsecutiveFailures) {
            $this->health[$proxy]['blocked_until'] = ($this->clock)() + $this->cooldownSeconds;
        }
    }

    /** @return list<array{proxy: string, healthy: bool, half_open_probe_available: bool, half_open_probe_in_flight: bool, cooldown_remaining_seconds: int, consecutive_failures: int, total_failures: int, successes: int}> */
    public function stats(): array
    {
        $now = ($this->clock)();
        $stats = [];
        foreach ($this->proxies as $proxy) {
            $h = $this->health[$proxy];
            $stats[] = [
                'proxy' => self::mask($proxy),
                'healthy' => $h['blocked_until'] === 0,
                'half_open_probe_available' => $h['blocked_until'] !== 0 && $h['blocked_until'] <= $now && !$h['probe_in_flight'],
                'half_open_probe_in_flight' => $h['probe_in_flight'],
                'cooldown_remaining_seconds' => max(0, $h['blocked_until'] - $now),
                'consecutive_failures' => $h['failures'],
                'total_failures' => $h['total_failures'],
                'successes' => $h['successes'],
            ];
        }

        return $stats;
    }

    public static function mask(string $proxy): string
    {
        $parts = parse_url($proxy);
        if (!is_array($parts) || !is_string($parts['scheme'] ?? null) || !is_string($parts['host'] ?? null)) {
            return '[invalid proxy]';
        }

        $scheme = strtolower($parts['scheme']);
        $host = $parts['host'];
        $authority = (isset($parts['user']) || isset($parts['pass'])) ? '***@' : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        // Do not expose proxy query strings, paths, or fragments: providers often put credentials in them.
        return $scheme . '://' . $authority . $host . $port;
    }
}
