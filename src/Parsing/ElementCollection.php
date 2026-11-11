<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Parsing;

use Countable;
use IteratorAggregate;
use Symfony\Component\DomCrawler\Crawler;
use Traversable;

/** @implements IteratorAggregate<int, Element> */
final readonly class ElementCollection implements Countable, IteratorAggregate
{
    /** @param list<Element> $elements */
    public function __construct(private array $elements)
    {
    }

    public static function fromCrawler(Crawler $crawler, string $baseUrl = ''): self
    {
        $elements = [];
        foreach ($crawler as $node) {
            if ($node instanceof \DOMElement) {
                $elements[] = Element::fromNode($node, $baseUrl);
            }
        }

        return new self($elements);
    }

    public function first(): ?Element
    {
        return $this->elements[0] ?? null;
    }

    public function last(): ?Element
    {
        if ($this->elements === []) {
            return null;
        }

        return $this->elements[array_key_last($this->elements)];
    }

    /** @return list<Element> */
    public function all(): array
    {
        return $this->elements;
    }

    /** @return list<mixed> */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->elements);
    }

    public function each(callable $callback): void
    {
        foreach ($this->elements as $index => $element) {
            $callback($element, $index);
        }
    }

    public function count(): int
    {
        return count($this->elements);
    }

    public function isEmpty(): bool
    {
        return $this->elements === [];
    }

    public function getIterator(): Traversable
    {
        yield from $this->elements;
    }
}
