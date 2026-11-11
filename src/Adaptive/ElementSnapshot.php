<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Adaptive;

use HElturkey\WebTrawler\Support\UrlSanitizer;
use HElturkey\WebTrawler\Parsing\Element;

final readonly class ElementSnapshot
{
    /**
     * @param array<string, string> $attributes
     * @param list<string> $classes
     * @param array{tag: string, id: string, classes: list<string>, position?: int}|null $parent
     * @param list<array{tag: string, id: string, classes: list<string>, position?: int}> $ancestors
     * @param list<array{tag: string, text: string, classes: list<string>}> $siblings
     */
    public function __construct(
        public string $tag,
        public string $text,
        public array $attributes,
        public array $classes,
        public ?array $parent,
        public array $ancestors,
        public array $siblings,
        public int $sameTagPosition,
        public string $sourceUrl,
        public int $createdAt,
        public int $schemaVersion = 1,
        public string $ownText = '',
        public string $accessibleName = '',
    ) {
    }

    public static function capture(Element $element, string $sourceUrl = ''): self
    {
        $allAttributes = $element->attributes();
        $attributeNames = [
            'id', 'role', 'aria-label', 'aria-labelledby', 'aria-describedby', 'aria-controls',
            'name', 'title', 'placeholder', 'for', 'href', 'src', 'alt', 'type', 'itemprop',
            'property', 'rel', 'content', 'data-testid', 'data-test', 'data-qa', 'data-cy',
        ];
        $attributes = [];
        foreach ($allAttributes as $name => $value) {
            $isKnown = in_array($name, $attributeNames, true);
            $isDataAttribute = str_starts_with($name, 'data-') && !self::isVolatileDataAttribute($name);
            if (!$isKnown && !$isDataAttribute) {
                continue;
            }
            if ($value === '' || self::isVolatileValue($name, $value)) {
                continue;
            }
            if ($name === 'content' && preg_match('/(?:csrf|token|nonce|api[_-]?key|secret)/i', (string) ($allAttributes['name'] ?? '')) === 1) {
                continue;
            }

            // Snapshots are persisted: remove credentials and secret parameters from anything that is a URL.
            if (self::isUrlAttribute($name, $value)) {
                $value = UrlSanitizer::sanitize($value);
            }

            $attributes[$name] = Element::limitText($value, 300);
        }

        $parent = $element->parent();
        $parentSignature = $parent === null ? null : self::signature($parent);
        $ancestors = [];
        foreach ($element->ancestors(8) as $ancestor) {
            $ancestors[] = self::signature($ancestor);
        }

        $siblings = array_map(
            static fn (array $sibling): array => [
                'tag' => $sibling['tag'],
                'text' => $sibling['text'],
                'classes' => self::stableClasses($sibling['classes']),
            ],
            $element->siblingContext(2),
        );

        return new self(
            tag: $element->tag(),
            text: Element::limitText($element->text(), 500),
            attributes: $attributes,
            classes: self::stableClasses($element->classTokens()),
            parent: $parentSignature,
            ancestors: $ancestors,
            siblings: $siblings,
            sameTagPosition: $element->siblingElementPosition(),
            sourceUrl: UrlSanitizer::sanitize($sourceUrl),
            createdAt: time(),
            schemaVersion: 2,
            ownText: Element::limitText($element->ownText(), 250),
            accessibleName: Element::limitText($element->accessibleName(), 250),
        );
    }

    /** @return array{tag: string, id: string, classes: list<string>, position: int} */
    private static function signature(Element $element): array
    {
        $id = (string) $element->attr('id');

        return [
            'tag' => $element->tag(),
            'id' => self::isVolatileValue('id', $id) ? '' : Element::limitText($id, 120),
            'classes' => self::stableClasses($element->classTokens()),
            'position' => $element->siblingElementPosition(),
        ];
    }

    /**
     * @param array<array-key, mixed> $classes
     * @return list<string>
     */
    private static function stableClasses(array $classes): array
    {
        $stable = [];
        foreach ($classes as $class) {
            if (is_string($class) && !self::isVolatileClass($class)) {
                $stable[] = $class;
            }
        }

        return array_values(array_unique($stable));
    }

    /** Whether a class token looks generated (CSS-in-JS / hashed) and therefore unstable across deploys. */
    public static function isVolatileClass(string $class): bool
    {
        return preg_match(
            '/^(?:css-[a-z0-9]{4,}|sc-[a-z0-9]{5,}|emotion-[a-z0-9]{4,}|jsx-[a-z0-9]{5,}|__[a-z0-9]{8,}|_[a-f0-9]{8,}|[a-z][a-z0-9_-]*__[a-z0-9_-]{5,}\d[a-z0-9_-]*)$/i',
            $class,
        ) === 1;
    }

    private static function isVolatileDataAttribute(string $name): bool
    {
        return preg_match('/^data-(?:(?:react|vue|ng|tracking|analytics|session|csrf|nonce)(?:-|$)|.*(?:token|secret|api[_-]?key|auth).*)/i', $name) === 1;
    }

    private static function isUrlAttribute(string $name, string $value): bool
    {
        if (in_array($name, ['href', 'src', 'action', 'formaction', 'poster', 'cite', 'data', 'ping', 'data-href', 'data-src', 'data-url', 'data-link'], true)) {
            return true;
        }

        return preg_match('~^(?:[a-z][a-z0-9+.\-]*:)?//~i', $value) === 1;
    }

    private static function isVolatileValue(string $name, string $value): bool
    {
        if ($name !== 'id') {
            return false;
        }

        return preg_match('/^(?::[a-z]\d+:|[0-9a-f]{8}-[0-9a-f-]{27,}|[a-f0-9]{20,}|(?:radix|headlessui|ember)[-_][a-z0-9-]*\d+)$/i', $value) === 1;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'tag' => $this->tag,
            'text' => $this->text,
            'attributes' => $this->attributes,
            'classes' => $this->classes,
            'parent' => $this->parent,
            'ancestors' => $this->ancestors,
            'siblings' => $this->siblings,
            'same_tag_position' => $this->sameTagPosition,
            'source_url' => $this->sourceUrl,
            'created_at' => $this->createdAt,
            'own_text' => $this->ownText,
            'accessible_name' => $this->accessibleName,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $schemaVersion = $data['schema_version'] ?? 1;
        if (!is_int($schemaVersion) || !in_array($schemaVersion, [1, 2], true)) {
            throw new \UnexpectedValueException('Unsupported adaptive snapshot schema version.');
        }

        return new self(
            tag: is_string($data['tag'] ?? null) ? strtolower($data['tag']) : '',
            text: is_string($data['text'] ?? null) ? $data['text'] : '',
            attributes: self::stringMap($data['attributes'] ?? null),
            classes: self::stringList($data['classes'] ?? null),
            parent: self::signatureFromArray($data['parent'] ?? null),
            ancestors: self::signatureList($data['ancestors'] ?? null),
            siblings: self::siblingList($data['siblings'] ?? null),
            sameTagPosition: max(0, (int) ($data['same_tag_position'] ?? 0)),
            sourceUrl: is_string($data['source_url'] ?? null) ? $data['source_url'] : '',
            createdAt: max(0, (int) ($data['created_at'] ?? 0)),
            schemaVersion: $schemaVersion,
            ownText: is_string($data['own_text'] ?? null) ? $data['own_text'] : '',
            accessibleName: is_string($data['accessible_name'] ?? null) ? $data['accessible_name'] : '',
        );
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter($value, 'is_string')));
    }

    /** @return array{tag: string, id: string, classes: list<string>, position?: int}|null */
    private static function signatureFromArray(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $signature = [
            'tag' => is_string($value['tag'] ?? null) ? strtolower($value['tag']) : '',
            'id' => is_string($value['id'] ?? null) ? $value['id'] : '',
            'classes' => self::stringList($value['classes'] ?? null),
        ];
        if (isset($value['position']) && is_numeric($value['position'])) {
            $signature['position'] = max(0, (int) $value['position']);
        }

        return $signature;
    }

    /** @return list<array{tag: string, id: string, classes: list<string>, position?: int}> */
    private static function signatureList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $signature) {
            $normalized = self::signatureFromArray($signature);
            if ($normalized !== null) {
                $result[] = $normalized;
            }
        }

        return $result;
    }

    /** @return list<array{tag: string, text: string, classes: list<string>}> */
    private static function siblingList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $sibling) {
            if (!is_array($sibling)) {
                continue;
            }
            $result[] = [
                'tag' => is_string($sibling['tag'] ?? null) ? strtolower($sibling['tag']) : '',
                'text' => is_string($sibling['text'] ?? null) ? $sibling['text'] : '',
                'classes' => self::stringList($sibling['classes'] ?? null),
            ];
        }

        return $result;
    }
}
