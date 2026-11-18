<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Contracts;

/**
 * Membership hint with no false negatives for values added to the current filter state.
 * Positive results may be false and must never be treated as proof of existence.
 */
interface BloomFilter
{
    public function add(string $value): void;

    public function mightContain(string $value): bool;

    public function clear(): void;
}
