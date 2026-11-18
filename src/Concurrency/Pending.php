<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Concurrency;

use LogicException;
use Throwable;

/**
 * A settle-once result holder for work that is still running.
 *
 * This is deliberately not a promise library: it has no scheduler and never waits. The transport that
 * created the object settles it from its own I/O mechanism (curl-multi, Revolt, a thread pool ...), and
 * the consumer reads it after AsyncTransportInterface::tick() returned. Callbacks run synchronously, on the
 * thread that settles the object.
 */
final class Pending
{
    private bool $settled = false;
    private mixed $value = null;
    private ?Throwable $error = null;

    /** @var list<callable(Pending): void> */
    private array $listeners = [];

    public static function resolved(mixed $value): self
    {
        $pending = new self();
        $pending->resolve($value);

        return $pending;
    }

    public static function rejected(Throwable $error): self
    {
        $pending = new self();
        $pending->reject($error);

        return $pending;
    }

    /** Settling twice is ignored, so a late timeout cannot overwrite a real result. */
    public function resolve(mixed $value): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->value = $value;
        $this->notify();
    }

    public function reject(Throwable $error): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->error = $error;
        $this->notify();
    }

    public function isSettled(): bool
    {
        return $this->settled;
    }

    public function isRejected(): bool
    {
        return $this->settled && $this->error !== null;
    }

    /** @throws Throwable the failure, when the work failed. */
    public function value(): mixed
    {
        if (!$this->settled) {
            throw new LogicException('The pending result has not settled yet.');
        }
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->value;
    }

    public function error(): ?Throwable
    {
        return $this->error;
    }

    /** @param callable(Pending): void $listener Called once, immediately when already settled. */
    public function onSettled(callable $listener): void
    {
        if ($this->settled) {
            $listener($this);

            return;
        }
        $this->listeners[] = $listener;
    }

    /**
     * A derived result. $onValue maps the value (an exception it throws rejects the derived result);
     * $onError may turn a failure into a value or throw a different exception. Without $onError failures
     * pass through unchanged.
     *
     * @param callable(mixed): mixed $onValue
     * @param (callable(Throwable): mixed)|null $onError
     */
    public function then(callable $onValue, ?callable $onError = null): self
    {
        $derived = new self();
        $this->onSettled(static function (self $source) use ($derived, $onValue, $onError): void {
            try {
                if ($source->error === null) {
                    $derived->resolve($onValue($source->value));
                } elseif ($onError !== null) {
                    $derived->resolve($onError($source->error));
                } else {
                    $derived->reject($source->error);
                }
            } catch (Throwable $exception) {
                $derived->reject($exception);
            }
        });

        return $derived;
    }

    private function notify(): void
    {
        $listeners = $this->listeners;
        $this->listeners = [];
        foreach ($listeners as $listener) {
            $listener($this);
        }
    }
}
