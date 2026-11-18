<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Parsing;

use Dom\Element as Html5Element;
use Dom\Text as Html5Text;
use DOMElement;
use DOMText;

/**
 * The HTML5 parser of PHP 8.4 produces Dom\* nodes, older code paths produce DOM* nodes. Both families expose
 * the same members the package uses, so these checks accept either.
 *
 * @internal
 */
final class DomNodes
{
    /** @phpstan-assert-if-true DOMElement|Html5Element $node */
    public static function isElement(mixed $node): bool
    {
        return $node instanceof DOMElement || $node instanceof Html5Element;
    }

    /** @phpstan-assert-if-true DOMText|Html5Text $node */
    public static function isText(mixed $node): bool
    {
        return $node instanceof DOMText || $node instanceof Html5Text;
    }
}
