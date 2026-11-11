<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Contracts;

interface ItemPipeline
{
    /**
     * Return null to drop an item; return an array to pass it to the next pipeline.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    public function process(array $item): ?array;
}
