<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Adaptive;

use HElturkey\WebTrawler\Parsing\Element;

final readonly class AdaptiveMatch
{
    /**
     * @param array<string, float> $signals Similarity from 0.0 to 1.0 for each signal.
     * @param list<string> $reasons Human-readable matching evidence.
     * @param array<string, float> $contributions Normalized contribution to the final score.
     */
    public function __construct(
        private Element $element,
        public float $confidence,
        public array $signals,
        public array $reasons,
        public array $contributions = [],
    ) {
    }

    public function element(): Element
    {
        return $this->element;
    }

    public function text(): string
    {
        return $this->element->text();
    }

    public function ownText(): string
    {
        return $this->element->ownText();
    }

    public function attr(string $name): ?string
    {
        return $this->element->attr($name);
    }

    public function tag(): string
    {
        return $this->element->tag();
    }

    public function html(): string
    {
        return $this->element->html();
    }

    public function outerHtml(): string
    {
        return $this->element->outerHtml();
    }
}
