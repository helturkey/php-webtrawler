<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Parsing;

use Closure;
use Dom\Element as Html5Element;
use Dom\Node as Html5Node;
use DOMElement;
use DOMNode;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

/**
 * Converts an HTML subtree to readable Markdown (CommonMark plus GitHub tables), for feeding pages to search
 * indexes, RAG pipelines and language models.
 *
 * The output is deliberately conservative:
 * - scripts, styles, frames, SVG and other non-content elements are dropped;
 * - elements that are hidden from readers (the `hidden` attribute, `aria-hidden="true"`, inline
 *   `display:none` / `visibility:hidden`) are dropped, which removes the most common hiding place for text
 *   aimed at language models. This is best effort, not a security boundary: text hidden by an external
 *   stylesheet cannot be seen here, so treat scraped content as untrusted input to a model;
 * - links keep only http, https, mailto and tel targets, images only http(s) sources;
 * - recursion is bounded, so a deeply nested hostile page cannot exhaust the stack.
 */
final class MarkdownConverter
{
    private const MAX_DEPTH = 200;

    private const DROP = [
        'script', 'style', 'noscript', 'template', 'svg', 'canvas', 'iframe', 'frame', 'frameset', 'object', 'embed',
        'head', 'meta', 'link', 'title', 'base', 'select', 'option', 'datalist', 'audio', 'video', 'source', 'track',
        'map', 'area', 'dialog', 'button', 'input', 'textarea', 'label',
    ];

    private const BLOCKS = [
        'address', 'article', 'aside', 'body', 'details', 'div', 'dl', 'fieldset', 'figure', 'figcaption', 'footer',
        'form', 'header', 'html', 'main', 'nav', 'p', 'section', 'summary', 'center',
    ];

    /** @var Closure(string): string */
    private Closure $resolve;

    /** @var array<string, true> */
    private array $skip;

    /**
     * @param string $baseUrl Relative links and image sources are made absolute against this URL.
     * @param list<string> $skipTags Extra lower-case tag names to drop with their content (for example nav, footer).
     */
    public function __construct(string $baseUrl = '', array $skipTags = [])
    {
        $this->resolve = static function (string $url) use ($baseUrl): string {
            if ($baseUrl === '') {
                return $url;
            }
            try {
                return (string) UriResolver::resolve(new Uri($baseUrl), new Uri($url));
            } catch (Throwable) {
                return $url;
            }
        };
        $this->skip = array_fill_keys(array_map('strtolower', $skipTags), true);
    }

    /**
     * Convert a node. An element is converted as itself, so selecting an `h1` yields "# Title" and selecting a
     * `ul` yields its list; a document node is converted through its children.
     */
    public function convert(DOMNode|Html5Node $root): string
    {
        if (DomNodes::isElement($root)) {
            return $this->tidy($this->element($root, 0, false));
        }

        return $this->tidy($this->children($root, 0, false));
    }

    /**
     * Strip trailing spaces and collapse runs of blank lines, but leave the inside of fenced code blocks exactly
     * as written: their whitespace is content.
     */
    private function tidy(string $markdown): string
    {
        $out = [];
        $fence = null;

        foreach (explode("\n", $markdown) as $line) {
            // Indentation and quote markers may precede a fence when the block sits in a list or quote.
            $probe = ltrim($line, " >\t");

            if ($fence !== null) {
                $out[] = $line;
                if (preg_match('/^(`{3,})\s*$/', $probe, $match) === 1 && strlen($match[1]) >= $fence) {
                    $fence = null;
                }
                continue;
            }

            $line = rtrim($line, " \t");
            if ($line === '') {
                if ($out !== [] && end($out) !== '') {
                    $out[] = '';
                }
                continue;
            }
            if (preg_match('/^(`{3,})[^`]*$/', $probe, $match) === 1) {
                $fence = strlen($match[1]);
            }
            $out[] = $line;
        }

        return trim(implode("\n", $out));
    }

    private function children(DOMNode|Html5Node $node, int $depth, bool $inPre): string
    {
        if ($depth > self::MAX_DEPTH) {
            return $this->text($node->textContent ?? '');
        }

        $out = '';
        foreach ($node->childNodes as $child) {
            if (DomNodes::isText($child)) {
                $out .= $inPre ? $child->data : $this->text($child->data);
                continue;
            }
            if (DomNodes::isElement($child)) {
                $out .= $this->element($child, $depth + 1, $inPre);
            }
        }

        return $out;
    }

    private function element(DOMElement|Html5Element $element, int $depth, bool $inPre): string
    {
        $tag = strtolower($element->nodeName);
        if (in_array($tag, self::DROP, true) || isset($this->skip[$tag]) || $this->isHidden($element)) {
            return '';
        }

        switch ($tag) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $text = $this->oneLine($this->children($element, $depth, false));

                return $text === '' ? '' : "\n\n" . str_repeat('#', (int) $tag[1]) . ' ' . $text . "\n\n";
            case 'br':
                return $inPre ? "\n" : "\\\n";
            case 'hr':
                return "\n\n---\n\n";
            case 'strong':
            case 'b':
                return $this->wrap($this->children($element, $depth, false), '**');
            case 'em':
            case 'i':
                return $this->wrap($this->children($element, $depth, false), '*');
            case 'del':
            case 's':
            case 'strike':
                return $this->wrap($this->children($element, $depth, false), '~~');
            case 'code':
                return $inPre ? $this->children($element, $depth, true) : $this->inlineCode($element->textContent ?? '');
            case 'pre':
                return $this->codeBlock($element);
            case 'a':
                return $this->link($element, $depth);
            case 'img':
                return $this->image($element);
            case 'ul':
            case 'ol':
                return "\n\n" . $this->list($element, $tag === 'ol', $depth) . "\n\n";
            case 'blockquote':
                $inner = trim($this->children($element, $depth, false));

                return $inner === '' ? '' : "\n\n" . $this->prefixLines($inner, '> ') . "\n\n";
            case 'table':
                return $this->table($element, $depth);
            case 'dt':
                return "\n\n" . $this->wrap($this->oneLine($this->children($element, $depth, false)), '**') . "\n\n";
            case 'li':
                return "\n" . $this->children($element, $depth, false) . "\n";
        }

        $inner = $this->children($element, $depth, $inPre);
        if (in_array($tag, self::BLOCKS, true) || $tag === 'dd' || $tag === 'tr') {
            return trim($inner) === '' ? '' : "\n\n" . trim($inner) . "\n\n";
        }

        return $inner;
    }

    private function link(DOMElement|Html5Element $element, int $depth): string
    {
        $label = $this->oneLine($this->children($element, $depth, false));
        $href = trim((string) ($element->hasAttribute('href') ? $element->getAttribute('href') : ''));
        if ($href === '' || str_starts_with($href, '#')) {
            return $label;
        }
        $url = ($this->resolve)($href);
        if (preg_match('/^(https?|mailto|tel):/i', $url) !== 1) {
            return $label;
        }

        return $label === '' ? '' : '[' . $label . '](' . $this->destination($url) . ')';
    }

    private function image(DOMElement|Html5Element $element): string
    {
        $src = trim((string) ($element->hasAttribute('src') ? $element->getAttribute('src') : ''));
        if ($src === '') {
            return '';
        }
        $url = ($this->resolve)($src);
        if (preg_match('/^https?:/i', $url) !== 1) {
            return '';
        }
        $alt = $element->hasAttribute('alt') ? $this->oneLine($this->text((string) $element->getAttribute('alt'))) : '';

        return '![' . $alt . '](' . $this->destination($url) . ')';
    }

    private function list(DOMElement|Html5Element $list, bool $ordered, int $depth): string
    {
        $number = 1;
        if ($ordered && $list->hasAttribute('start') && ctype_digit((string) $list->getAttribute('start'))) {
            $number = max(0, min(999_999, (int) $list->getAttribute('start')));
        }

        $items = [];
        foreach ($list->childNodes as $child) {
            if (!DomNodes::isElement($child) || strtolower($child->nodeName) !== 'li' || $this->isHidden($child)) {
                continue;
            }
            $body = trim($this->children($child, $depth + 1, false));
            if ($body === '') {
                continue;
            }
            $marker = $ordered ? ($number++) . '. ' : '- ';
            $indent = str_repeat(' ', strlen($marker));
            $lines = explode("\n", $body);
            $first = array_shift($lines);
            $items[] = $marker . $first . ($lines === [] ? '' : "\n" . implode("\n", array_map(
                static fn (string $line): string => $line === '' ? '' : $indent . $line,
                $lines,
            )));
        }

        return implode("\n", $items);
    }

    private function table(DOMElement|Html5Element $table, int $depth): string
    {
        $rows = [];
        $this->collectRows($table, $rows, $depth);
        if ($rows === []) {
            return '';
        }

        $columns = max(array_map('count', $rows));
        $format = static function (array $cells) use ($columns): string {
            $cells = array_pad($cells, $columns, '');

            return '| ' . implode(' | ', $cells) . ' |';
        };

        $lines = [$format($rows[0]), '| ' . implode(' | ', array_fill(0, $columns, '---')) . ' |'];
        foreach (array_slice($rows, 1) as $row) {
            $lines[] = $format($row);
        }

        return "\n\n" . implode("\n", $lines) . "\n\n";
    }

    /** @param list<list<string>> $rows */
    private function collectRows(DOMElement|Html5Element $node, array &$rows, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        foreach ($node->childNodes as $child) {
            if (!DomNodes::isElement($child) || $this->isHidden($child)) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if ($tag === 'tr') {
                $cells = [];
                foreach ($child->childNodes as $cell) {
                    if (DomNodes::isElement($cell) && in_array(strtolower($cell->nodeName), ['td', 'th'], true) && !$this->isHidden($cell)) {
                        $cells[] = str_replace('|', '\|', $this->oneLine($this->children($cell, $depth + 1, false)));
                    }
                }
                if ($cells !== []) {
                    $rows[] = $cells;
                }
            } elseif (in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                $this->collectRows($child, $rows, $depth + 1);
            }
        }
    }

    private function codeBlock(DOMElement|Html5Element $pre): string
    {
        $code = rtrim($this->children($pre, 0, true), "\n");
        $language = '';
        foreach ($pre->childNodes as $child) {
            if (DomNodes::isElement($child) && strtolower($child->nodeName) === 'code' && $child->hasAttribute('class')
                && preg_match('/(?:^|\s)(?:language|lang)-([A-Za-z0-9_+#.-]{1,30})(?:\s|$)/', (string) $child->getAttribute('class'), $match) === 1) {
                $language = $match[1];
                break;
            }
        }
        $longestRun = 0;
        if (preg_match_all('/`+/', $code, $runs) > 0) {
            $longestRun = max(0, ...array_map('strlen', $runs[0]));
        }
        $fence = str_repeat('`', max(3, $longestRun + 1));

        return "\n\n" . $fence . $language . "\n" . $code . "\n" . $fence . "\n\n";
    }

    private function inlineCode(string $code): string
    {
        $code = trim((string) preg_replace('/\s+/', ' ', $code));
        if ($code === '') {
            return '';
        }
        $longestRun = 0;
        if (preg_match_all('/`+/', $code, $runs) > 0) {
            $longestRun = max(0, ...array_map('strlen', $runs[0]));
        }
        $fence = str_repeat('`', $longestRun + 1);
        $pad = str_starts_with($code, '`') || str_ends_with($code, '`') ? ' ' : '';

        return $fence . $pad . $code . $pad . $fence;
    }

    private function wrap(string $inner, string $mark): string
    {
        $trimmed = trim($inner);
        if ($trimmed === '') {
            return $inner;
        }
        $leading = str_starts_with($inner, ' ') ? ' ' : '';
        $trailing = str_ends_with($inner, ' ') ? ' ' : '';

        return $leading . $mark . $trimmed . $mark . $trailing;
    }

    private function prefixLines(string $text, string $prefix): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => rtrim($prefix . $line),
            explode("\n", $text),
        ));
    }

    private function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace("\\\n", ' ', $text)));
    }

    /** Collapse whitespace like a browser does and escape characters Markdown would interpret. */
    private function text(string $text): string
    {
        $text = (string) preg_replace('/[ \t\r\n\f]+/', ' ', $text);

        return (string) preg_replace('/([\\\\`*_\[\]<>])/', '\\\\$1', $text);
    }

    /** Link destinations: no raw spaces or characters that would end the destination early. */
    private function destination(string $url): string
    {
        return strtr($url, [' ' => '%20', '(' => '%28', ')' => '%29', '<' => '%3C', '>' => '%3E', "\n" => '', "\r" => '']);
    }

    private function isHidden(DOMElement|Html5Element $element): bool
    {
        if ($element->hasAttribute('hidden') || strtolower(trim((string) $element->getAttribute('aria-hidden'))) === 'true') {
            return true;
        }
        if (strtolower((string) $element->getAttribute('type')) === 'hidden') {
            return true;
        }
        $style = strtolower((string) $element->getAttribute('style'));

        return $style !== '' && preg_match('/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden)\s*(?:!important\s*)?(?:;|$)/', $style) === 1;
    }
}
