<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Spider;

final readonly class SpiderItem
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data)
    {
    }
}
