<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Adaptive;

use HElturkey\WebTrawler\Parsing\Element;

final readonly class AdaptiveResult
{
    public string $status;

    /**
     * @param list<AdaptiveMatch> $candidates
     */
    public function __construct(
        public ?AdaptiveMatch $bestMatch,
        public array $candidates,
        public bool $accepted,
        public ?string $reason = null,
        ?string $status = null,
        public int $inspectedCandidates = 0,
        public bool $candidateSetTruncated = false,
        public ?float $margin = null,
    ) {
        $this->status = $status ?? ($accepted ? 'accepted' : 'rejected');
    }

    public function element(): ?Element
    {
        return $this->accepted ? $this->bestMatch?->element() : null;
    }

    public function confidence(): float
    {
        return $this->bestMatch === null ? 0.0 : $this->bestMatch->confidence;
    }
}
