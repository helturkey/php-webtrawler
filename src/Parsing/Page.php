<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Parsing;

use DOMDocument;
use DOMElement;
use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Adaptive\AdaptiveSelection;
use HElturkey\WebTrawler\Adaptive\ElementSnapshot;
use HElturkey\WebTrawler\Contracts\SnapshotStore;
use HElturkey\WebTrawler\Http\FetchResponse;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use HElturkey\WebTrawler\Support\Text;
use Symfony\Component\DomCrawler\Crawler;

final readonly class Page
{
    /** @var list<string> Elements whose text is never part of Page::text(). */
    private const NON_TEXT_ELEMENTS = ['script', 'style', 'noscript', 'template'];

    /** @var list<string> Elements that separate words visually even without whitespace in the markup. */
    private const BLOCK_ELEMENTS = [
        'address', 'article', 'aside', 'blockquote', 'br', 'dd', 'div', 'dl', 'dt', 'fieldset', 'figcaption',
        'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav',
        'ol', 'p', 'pre', 'section', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    private function __construct(
        private Crawler $crawler,
        private DOMDocument $document,
        private string $url,
        private string $baseUrl,
        private ?FetchResponse $response,
        private SnapshotStore $snapshotStore,
        private AdaptiveMatcher $adaptiveMatcher,
    ) {
    }

    public static function fromResponse(
        FetchResponse $response,
        SnapshotStore $snapshotStore,
        AdaptiveMatcher $adaptiveMatcher,
        ?string $encoding = null,
    ): self {
        // Raw HTTP bytes. An explicit $encoding wins; otherwise honor BOM, Content-Type
        // charset and <meta> like a browser would.
        $html = $encoding !== null
            ? HtmlDecoder::decodeAs($response->body, $encoding)
            : HtmlDecoder::decode($response->body, $response->header('Content-Type'));

        return self::fromHtml($html, $response->effectiveUrl, $snapshotStore, $adaptiveMatcher, $response);
    }

    public static function fromHtml(
        string $html,
        string $url,
        SnapshotStore $snapshotStore,
        AdaptiveMatcher $adaptiveMatcher,
        ?FetchResponse $response = null,
    ): self {
        // UTF-8 input (browser output, already-decoded bodies) is only normalized. Raw
        // bytes in another encoding are still decoded via their <meta>/header charset.
        $html = HtmlDecoder::normalizeUtf8(
            Text::isUtf8($html) ? $html : HtmlDecoder::decode($html, $response?->header('Content-Type')),
        );
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            // Drop the encoding hint we prepended, wherever the parser left it.
            foreach (iterator_to_array($document->childNodes) as $child) {
                if ($child->nodeType === XML_PI_NODE) {
                    $document->removeChild($child);
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        // An empty or unparsable body yields no root element, which makes later
        // queries raise PHP warnings. Always provide a (possibly empty) <html> root.
        if ($document->documentElement === null) {
            $document->appendChild($document->createElement('html'));
        }

        $crawler = new Crawler($document, $url);

        return new self($crawler, $document, $url, self::documentBaseUrl($document, $url), $response, $snapshotStore, $adaptiveMatcher);
    }

    public function css(string $selector): ElementCollection
    {
        return ElementCollection::fromCrawler($this->crawler->filter($selector), $this->url);
    }

    public function xpath(string $expression): ElementCollection
    {
        return ElementCollection::fromCrawler($this->crawler->filterXPath($expression), $this->url);
    }

    public function url(): string
    {
        return $this->url;
    }

    public function response(): ?FetchResponse
    {
        return $this->response;
    }

    /** @return list<array<string, mixed>> */
    public function networkRequests(): array
    {
        $requests = $this->response?->meta['network_requests'] ?? [];

        return is_array($requests) ? array_values($requests) : [];
    }

    /** @return list<array<string, mixed>> XHR and Fetch requests captured by a browser driver. */
    public function apiResponses(): array
    {
        return array_values(array_filter(
            $this->networkRequests(),
            static fn (array $request): bool => in_array($request['resource_type'] ?? null, ['xhr', 'fetch'], true)
                && isset($request['status'])
                && !isset($request['failure']),
        ));
    }

    /** @return list<array<string, mixed>> Requests that failed before receiving a response. */
    public function failedRequests(): array
    {
        return array_values(array_filter(
            $this->networkRequests(),
            static fn (array $request): bool => isset($request['failure']),
        ));
    }

    /** Resolve a link against the page, honoring a valid http(s) <base href>. */
    public function resolve(string $url): string
    {
        return (string) UriResolver::resolve(new Uri($this->baseUrl), new Uri($url));
    }

    private static function documentBaseUrl(DOMDocument $document, string $pageUrl): string
    {
        $base = $document->getElementsByTagName('base')->item(0);
        $href = $base instanceof DOMElement ? trim($base->getAttribute('href')) : '';
        if ($href === '') {
            return $pageUrl;
        }

        try {
            $resolved = UriResolver::resolve(new Uri($pageUrl), new Uri($href));
        } catch (\InvalidArgumentException) {
            return $pageUrl;
        }

        return in_array(strtolower($resolved->getScheme()), ['http', 'https'], true) ? (string) $resolved : $pageUrl;
    }

    public function title(): ?string
    {
        return $this->css('title')->first()?->text();
    }

    public function text(): string
    {
        $parts = [];
        if ($this->document->documentElement !== null) {
            self::collectText($this->document->documentElement, $parts);
        }

        return Text::normalizeWhitespace(implode('', $parts));
    }

    /** @param list<string> $parts */
    private static function collectText(\DOMNode $node, array &$parts): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $parts[] = $child->data;
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::NON_TEXT_ELEMENTS, true)) {
                continue;
            }

            $isBlock = in_array($tag, self::BLOCK_ELEMENTS, true);
            if ($isBlock) {
                $parts[] = ' ';
            }
            self::collectText($child, $parts);
            if ($isBlock) {
                $parts[] = ' ';
            }
        }
    }

    public function html(): string
    {
        return $this->document->saveHTML() ?: '';
    }

    public function remember(string $key, Element $element): void
    {
        $this->snapshotStore->put($key, ElementSnapshot::capture($element, $this->url));
    }

    public function adaptive(string $key, float $minimumConfidence = 0.52, float $minimumMargin = 0.045): AdaptiveSelection
    {
        $snapshot = $this->snapshotStore->get($key);

        return new AdaptiveSelection($this, $snapshot, $this->adaptiveMatcher, $minimumConfidence, $minimumMargin);
    }

    /** @return list<Element> */
    public function allElements(int $limit = 5000): array
    {
        $elements = [];
        $positions = [];
        $nodes = $this->document->getElementsByTagName('*');
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, ['html', 'head', 'body', 'script', 'style', 'meta', 'link', 'noscript'], true)) {
                continue;
            }

            $parentPath = $node->parentNode?->getNodePath() ?? '';
            $positions[$parentPath][$tag] = ($positions[$parentPath][$tag] ?? 0) + 1;
            $elements[] = Element::fromNode($node, $this->url, $positions[$parentPath][$tag]);
            if (count($elements) >= $limit) {
                break;
            }
        }

        return $elements;
    }
}
