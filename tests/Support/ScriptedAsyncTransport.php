<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Support;

use HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface;
use Closure;
use HElturkey\WebTrawler\Concurrency\Pending;
use HElturkey\WebTrawler\Contracts\Http\CancellableTransportInterface;
use HElturkey\WebTrawler\Response\FetchResponse;

/**
 * An async transport written the way a third-party package would write one: no Guzzle, no event-loop
 * library, only the AsyncTransportInterface contract. The test decides which open request finishes at each tick().
 */
final class ScriptedAsyncTransport implements CancellableTransportInterface
{
    /** @var list<array{0: string, 1: Pending}> */
    private array $open = [];

    /** @var list<string> "start:URL" / "done:URL" in the order they happened. */
    public array $events = [];

    public int $peak = 0;

    /** @param Closure(list<string>): int $choose Receives the open URLs (dispatch order) and returns the index to finish. */
    public function __construct(private readonly Closure $choose)
    {
    }

    public static function lastInFirstOut(): self
    {
        return new self(static fn (array $open): int => count($open) - 1);
    }

    public static function firstInFirstOut(): self
    {
        return new self(static fn (array $open): int => 0);
    }

    public function supportsAsync(): bool
    {
        return true;
    }

    public function send(string $method, string $url, array $options = []): FetchResponse
    {
        throw new \LogicException('This transport is asynchronous only.');
    }

    public function sendAsync(string $method, string $url, array $options = []): Pending
    {
        $pending = new Pending();
        $this->open[] = [$url, $pending];
        $this->events[] = 'start:' . $url;
        $this->peak = max($this->peak, count($this->open));

        return $pending;
    }

    public function tick(?float $timeoutSeconds = null): void
    {
        if ($this->open === []) {
            return;
        }
        $index = ($this->choose)(array_column($this->open, 0));
        [$removed] = array_splice($this->open, $index, 1);
        [$url, $pending] = $removed;
        $this->events[] = 'done:' . $url;
        $pending->resolve(new FetchResponse(200, ['Content-Type' => ['text/html; charset=utf-8']], '<p>' . $url . '</p>', $url, $url, 1.0));
    }

    public int $cancelled = 0;

    public function cancelAll(): void
    {
        foreach ($this->open as [, $pending]) {
            $pending->reject(new \RuntimeException('cancelled'));
            ++$this->cancelled;
        }
        $this->open = [];
    }

    /** @return list<string> */
    public function completionOrder(): array
    {
        $order = [];
        foreach ($this->events as $event) {
            if (str_starts_with($event, 'done:')) {
                $order[] = substr($event, 5);
            }
        }

        return $order;
    }
}
