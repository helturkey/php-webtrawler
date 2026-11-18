<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Support;

use HElturkey\WebTrawler\Contracts\BloomFilter as BloomFilterContract;
use InvalidArgumentException;

/**
 * Compact in-memory Bloom filter used only to avoid SQLite lookups for definite misses.
 *
 * A positive result is a hint, not proof. SQLite's UNIQUE key remains authoritative, so a
 * false positive or an out-of-date filter can never cause a new URL to be discarded.
 * The filter is rebuilt from SQLite when the store opens; no custom serialization is needed.
 *
 * @internal
 */
final class BloomFilter implements BloomFilterContract
{
    private const MAX_BITS = 1_000_000_000;

    private string $bits;
    private int $bitCount;
    private int $hashCount;
    private int $itemsAdded = 0;

    public function __construct(int $expectedItems = 100_000, float $falsePositiveProbability = 0.01)
    {
        if ($expectedItems < 1) {
            throw new InvalidArgumentException('Bloom filter capacity must be at least 1.');
        }
        if (!is_finite($falsePositiveProbability) || $falsePositiveProbability <= 0.0 || $falsePositiveProbability >= 1.0) {
            throw new InvalidArgumentException('False-positive probability must be greater than 0 and less than 1.');
        }

        $ln2 = log(2.0);
        $bits = (int) ceil(-$expectedItems * log($falsePositiveProbability) / ($ln2 * $ln2));
        if ($bits < 8 || $bits > self::MAX_BITS) {
            throw new InvalidArgumentException('Requested Bloom filter would exceed the supported bitset size.');
        }

        $hashCount = max(1, (int) round(($bits / $expectedItems) * $ln2));
        if ($hashCount > 64) {
            throw new InvalidArgumentException('Requested false-positive probability requires too many hash probes.');
        }

        $this->bitCount = $bits;
        $this->hashCount = $hashCount;
        $this->bits = str_repeat("\0", intdiv($bits + 7, 8));
    }

    public function add(string $value): void
    {
        foreach ($this->indexes($value) as $index) {
            $byteIndex = intdiv($index, 8);
            $mask = 1 << ($index % 8);
            $this->bits[$byteIndex] = chr(ord($this->bits[$byteIndex]) | $mask);
        }

        $this->itemsAdded++;
    }

    public function mightContain(string $value): bool
    {
        foreach ($this->indexes($value) as $index) {
            $byteIndex = intdiv($index, 8);
            $mask = 1 << ($index % 8);
            if ((ord($this->bits[$byteIndex]) & $mask) === 0) {
                return false;
            }
        }

        return true;
    }

    public function clear(): void
    {
        $this->bits = str_repeat("\0", strlen($this->bits));
        $this->itemsAdded = 0;
    }

    public function itemsAdded(): int
    {
        return $this->itemsAdded;
    }

    /** @return list<int> */
    private function indexes(string $value): array
    {
        $digest = hash('sha256', $value, true);

        // Two 31-bit SHA-256 words produce all positions without unsigned-integer portability issues.
        $first = $this->unsigned31($digest, 0);
        $step = $this->unsigned31($digest, 4) % $this->bitCount;
        if ($step === 0) {
            $step = 1;
        }
        // A step that shares a divisor with the bit count would visit only part of the bitset.
        while ($this->greatestCommonDivisor($step, $this->bitCount) !== 1) {
            $step++;
            if ($step >= $this->bitCount) {
                $step = 1;
            }
        }

        // Increment modulo the bit count instead of multiplying i * step, which can overflow
        // on 32-bit PHP builds when a large filter capacity is requested.
        $index = $first % $this->bitCount;
        $indexes = [];
        for ($i = 0; $i < $this->hashCount; $i++) {
            $indexes[] = $index;
            $index = ($index + $step) % $this->bitCount;
        }

        return $indexes;
    }

    private function unsigned31(string $bytes, int $offset): int
    {
        return ((ord($bytes[$offset]) & 0x7F) * 16_777_216)
            + (ord($bytes[$offset + 1]) * 65_536)
            + (ord($bytes[$offset + 2]) * 256)
            + ord($bytes[$offset + 3]);
    }

    private function greatestCommonDivisor(int $left, int $right): int
    {
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return $left;
    }
}
