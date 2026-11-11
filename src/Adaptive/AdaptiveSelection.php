<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Adaptive;

use HElturkey\WebTrawler\Parsing\Page;

final readonly class AdaptiveSelection
{
    public function __construct(
        private Page $page,
        private ?ElementSnapshot $snapshot,
        private AdaptiveMatcher $matcher,
        private float $minimumConfidence,
        private float $minimumMargin,
    ) {
    }

    public function first(int $resultLimit = 5): AdaptiveResult
    {
        if ($resultLimit < 1) {
            throw new \InvalidArgumentException('The adaptive result limit must be positive.');
        }

        if ($this->snapshot === null) {
            return new AdaptiveResult(null, [], false, 'No saved snapshot exists for this key.', 'no_snapshot');
        }

        return $this->matcher->match(
            $this->page,
            $this->snapshot,
            $this->minimumConfidence,
            $this->minimumMargin,
            $resultLimit,
        );
    }
}
