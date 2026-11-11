<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Parsing;

use DOMElement;
use HElturkey\WebTrawler\Support\Text;
use Symfony\Component\DomCrawler\Crawler;

final readonly class Element
{
    public function __construct(
        private Crawler $crawler,
        private string $baseUrl = '',
        private ?int $knownSiblingElementPosition = null,
    ) {
    }

    public function text(): string
    {
        return trim($this->crawler->text('', true));
    }

    /** Text nodes directly owned by this element, excluding descendant elements. */
    public function ownText(): string
    {
        $parts = [];
        foreach ($this->node()->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $value = trim($child->nodeValue ?? '');
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        return self::normalizeWhitespace(implode(' ', $parts));
    }

    /** Best-effort accessible label available from the element's own attributes. */
    public function accessibleName(): string
    {
        foreach (['aria-label', 'title', 'alt', 'placeholder'] as $attribute) {
            $value = trim((string) $this->attr($attribute));
            if ($value !== '') {
                return self::normalizeWhitespace($value);
            }
        }

        return '';
    }

    public function html(): string
    {
        return $this->crawler->html();
    }

    public function outerHtml(): string
    {
        return $this->crawler->outerHtml();
    }

    public function attr(string $name): ?string
    {
        return $this->crawler->attr($name);
    }

    public function hasAttr(string $name): bool
    {
        return $this->attr($name) !== null;
    }

    public function tag(): string
    {
        return strtolower($this->node()->tagName);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $attributes = [];
        foreach ($this->node()->attributes as $attribute) {
            $attributes[$attribute->nodeName] = $attribute->nodeValue ?? '';
        }

        return $attributes;
    }

    public function css(string $selector): ElementCollection
    {
        return ElementCollection::fromCrawler($this->crawler->filter($selector), $this->baseUrl);
    }

    public function xpath(string $expression): ElementCollection
    {
        return ElementCollection::fromCrawler($this->crawler->filterXPath($expression), $this->baseUrl);
    }

    public function parent(): ?self
    {
        $parent = $this->node()->parentNode;

        return $parent instanceof DOMElement ? self::fromNode($parent, $this->baseUrl) : null;
    }

    public function children(): ElementCollection
    {
        $elements = [];
        foreach ($this->node()->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $elements[] = self::fromNode($child, $this->baseUrl);
            }
        }

        return new ElementCollection($elements);
    }

    public function siblings(): ElementCollection
    {
        $parent = $this->node()->parentNode;
        if ($parent === null) {
            return new ElementCollection([]);
        }

        $elements = [];
        foreach ($parent->childNodes as $sibling) {
            if ($sibling instanceof DOMElement && !$sibling->isSameNode($this->node())) {
                $elements[] = self::fromNode($sibling, $this->baseUrl);
            }
        }

        return new ElementCollection($elements);
    }

    /** @return list<Element> Nearest ancestor first. */
    public function ancestors(int $limit = 8): array
    {
        $result = [];
        $current = $this->node()->parentNode;

        while ($current instanceof DOMElement && count($result) < $limit) {
            $result[] = self::fromNode($current, $this->baseUrl);
            $current = $current->parentNode;
        }

        return $result;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function node(): DOMElement
    {
        $node = $this->crawler->getNode(0);
        if (!$node instanceof DOMElement) {
            throw new \LogicException('The selected node is not an HTML element.');
        }

        return $node;
    }

    public static function fromNode(
        DOMElement $node,
        string $baseUrl = '',
        ?int $siblingElementPosition = null,
    ): self {
        return new self(new Crawler($node, $baseUrl), $baseUrl, $siblingElementPosition);
    }

    public function siblingElementPosition(): int
    {
        if ($this->knownSiblingElementPosition !== null) {
            return $this->knownSiblingElementPosition;
        }
        if ($this->node()->parentNode === null) {
            return 0;
        }

        $position = 1;
        $currentTag = $this->tag();
        for ($sibling = $this->node()->previousSibling; $sibling !== null; $sibling = $sibling->previousSibling) {
            if ($sibling instanceof DOMElement && strtolower($sibling->tagName) === $currentTag) {
                $position++;
            }
        }

        return $position;
    }

    /** @return list<array{tag: string, text: string, classes: list<string>}> */
    public function siblingContext(int $limit = 2): array
    {
        if ($limit < 1) {
            return [];
        }

        $before = [];
        for ($sibling = $this->node()->previousSibling; $sibling !== null && count($before) < $limit; $sibling = $sibling->previousSibling) {
            if ($sibling instanceof DOMElement) {
                $before[] = $this->siblingSignature(self::fromNode($sibling, $this->baseUrl));
            }
        }
        $before = array_reverse($before);

        $after = [];
        for ($sibling = $this->node()->nextSibling; $sibling !== null && count($after) < $limit; $sibling = $sibling->nextSibling) {
            if ($sibling instanceof DOMElement) {
                $after[] = $this->siblingSignature(self::fromNode($sibling, $this->baseUrl));
            }
        }

        return array_merge($before, $after);
    }

    /** @return array{tag: string, text: string, classes: list<string>} */
    private function siblingSignature(self $element): array
    {
        return [
            'tag' => $element->tag(),
            'text' => self::limitText($element->text(), 100),
            'classes' => $element->classTokens(),
        ];
    }

    /** @return list<string> */
    public function classTokens(): array
    {
        $classes = preg_split('/\s+/u', trim((string) $this->attr('class')), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($classes ?: []));
    }

    public static function limitText(string $text, int $length): string
    {
        return Text::truncateChars(self::normalizeWhitespace($text), $length);
    }

    private static function normalizeWhitespace(string $text): string
    {
        return Text::normalizeWhitespace($text);
    }
}
