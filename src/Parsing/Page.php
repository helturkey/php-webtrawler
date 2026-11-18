<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Parsing;

use DOMDocument;
use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Adaptive\AdaptiveSelection;
use HElturkey\WebTrawler\Adaptive\ElementSnapshot;
use HElturkey\WebTrawler\Contracts\SnapshotStore;
use HElturkey\WebTrawler\Response\FetchResponse;
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
        // Browser output is already decoded text: the original Content-Type charset must not decode it twice.
        if (($response->meta[FetchResponse::META_DECODED] ?? false) === true) {
            return self::fromHtml($response->body, $response->effectiveUrl, $snapshotStore, $adaptiveMatcher, $response);
        }

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
        // symfony/dom-crawler 7.4+ parses with the HTML5 algorithm (the same tree a browser builds), so
        // misnested tags, implied elements and raw-text elements behave like they do in Chromium.
        $crawler = new Crawler(null, $url);
        $crawler->addHtmlContent($html, 'UTF-8');

        $root = $crawler->getNode(0);
        $document = $root instanceof \DOMNode ? $root->ownerDocument : null;

        if (!$document instanceof DOMDocument) {
            // An absent or unsupported owner document must still give us a queryable (empty) document.
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->appendChild($document->createElement('html'));
            $crawler = new Crawler($document, $url);
        }

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
        $href = DomNodes::isElement($base) ? trim($base->getAttribute('href') ?? '') : '';
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
    private static function collectText(\DOMNode|\Dom\Node $node, array &$parts): void
    {
        foreach ($node->childNodes as $child) {
            if (DomNodes::isText($child)) {
                $parts[] = $child->data;
                continue;
            }
            if (!DomNodes::isElement($child)) {
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

    /**
     * The page as Markdown, ready for search indexes, RAG pipelines and language models.
     *
     * @param string|null $selector CSS selector of the part to convert (all matches are joined); null converts the body.
     * @param bool $mainContent Prefer <main>, <article> or [role=main] and drop <nav>, <footer> and <aside>
     *                          (ignored when a selector is given).
     */
    public function markdown(?string $selector = null, bool $mainContent = false): string
    {
        $converter = new MarkdownConverter($this->baseUrl, $mainContent && $selector === null ? ['nav', 'footer', 'aside'] : []);

        if ($selector !== null) {
            $parts = [];
            foreach ($this->css($selector) as $element) {
                $parts[] = $converter->convert($element->node());
            }

            return implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== ''));
        }
        if ($mainContent) {
            $main = $this->css('main, article, [role="main"]')->first();
            if ($main !== null) {
                return $converter->convert($main->node());
            }
        }
        $body = $this->css('body')->first();

        return $converter->convert($body !== null ? $body->node() : $this->document);
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
        foreach ($this->crawler->filter('*') as $node) {
            if (!$node instanceof \DOMElement) {
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
