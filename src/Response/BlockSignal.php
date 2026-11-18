<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Response;

/** Why a response looks like an anti-bot block or challenge rather than the requested page. */
final readonly class BlockSignal
{
    public function __construct(
        public string $vendor,
        public string $reason,
        public int $status,
    ) {
    }
}
