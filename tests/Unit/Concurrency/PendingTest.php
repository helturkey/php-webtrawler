<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Concurrency;

use HElturkey\WebTrawler\Concurrency\Pending;
use PHPUnit\Framework\TestCase;

final class PendingTest extends TestCase
{
    public function test_it_settles_once_and_ignores_later_attempts(): void
    {
        $pending = new Pending();
        self::assertFalse($pending->isSettled());

        $pending->resolve('first');
        $pending->resolve('second');
        $pending->reject(new \RuntimeException('late'));

        self::assertTrue($pending->isSettled());
        self::assertFalse($pending->isRejected());
        self::assertSame('first', $pending->value());
    }

    public function test_reading_before_settling_is_a_logic_error(): void
    {
        $this->expectException(\LogicException::class);
        (new Pending())->value();
    }

    public function test_a_rejected_value_rethrows_the_original_failure(): void
    {
        $failure = new \RuntimeException('boom');
        $pending = Pending::rejected($failure);

        self::assertTrue($pending->isRejected());
        try {
            $pending->value();
            self::fail('value() must throw.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function test_listeners_run_once_when_settled_and_immediately_when_already_settled(): void
    {
        $calls = [];
        $pending = new Pending();
        $pending->onSettled(static function (Pending $p) use (&$calls): void {
            $calls[] = 'early:' . $p->value();
        });
        self::assertSame([], $calls);

        $pending->resolve(1);
        $pending->onSettled(static function (Pending $p) use (&$calls): void {
            $calls[] = 'late:' . $p->value();
        });

        self::assertSame(['early:1', 'late:1'], $calls);
    }

    public function test_then_maps_values_and_failures_into_a_derived_result(): void
    {
        $source = new Pending();
        $mapped = $source->then(static fn (mixed $v): int => $v * 2);
        $recovered = $source->then(static fn (mixed $v): int => throw new \DomainException('mapper failed'), static fn (\Throwable $e): string => 'unused');
        $source->resolve(21);

        self::assertSame(42, $mapped->value());
        self::assertTrue($recovered->isRejected());
        self::assertInstanceOf(\DomainException::class, $recovered->error());

        $failing = new Pending();
        $passThrough = $failing->then(static fn (mixed $v): mixed => $v);
        $handled = $failing->then(static fn (mixed $v): mixed => $v, static fn (\Throwable $e): string => 'recovered: ' . $e->getMessage());
        $failing->reject(new \RuntimeException('nope'));

        self::assertTrue($passThrough->isRejected());
        self::assertSame('recovered: nope', $handled->value());
    }
}
