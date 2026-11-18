<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Support;

use HElturkey\WebTrawler\Support\BloomFilter;
use PHPUnit\Framework\TestCase;

final class BloomFilterTest extends TestCase
{
    public function test_it_distinguishes_definite_absence_from_possible_presence(): void
    {
        $filter = new BloomFilter(1_000, 0.001);

        self::assertFalse($filter->mightContain('https://example.test/a'));
        $filter->add('https://example.test/a');
        self::assertTrue($filter->mightContain('https://example.test/a'));
        self::assertSame(1, $filter->itemsAdded());
    }

    public function test_clear_resets_bits_and_count(): void
    {
        $filter = new BloomFilter(100, 0.01);
        $filter->add('item');
        $filter->clear();

        self::assertSame(0, $filter->itemsAdded());
        self::assertFalse($filter->mightContain('item'));
    }

    public function test_it_rejects_invalid_capacity_and_probability(): void
    {
        foreach ([static fn () => new BloomFilter(0), static fn () => new BloomFilter(100, 1.0)] as $factory) {
            try {
                $factory();
                self::fail('Expected invalid Bloom filter configuration to be rejected.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
