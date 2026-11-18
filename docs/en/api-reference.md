# API reference

This page summarizes the supported entry points. The installed source and PHP signatures remain authoritative for exact optional argument types.

## Main facade

`HElturkey\WebTrawler\Scraper`

- `Scraper::create(...)`: factory accepting optional HTTP transport, snapshot store, adaptive matcher, default fetch options, enforced-default-security flag, and default retry policy.
- `fetch(string $url, ?FetchOptions $options = null): Page`
- `get(string $url, ?FetchOptions $options = null): Page`
- `post(string $url, array $data = [], ?FetchOptions $options = null): Page`
- `session(): Session`
- `crawl(Spider $spider, array $middlewares = [], array $pipelines = [], ?JsonCrawlStateStore $state = null, ?callable $shouldStop = null, ?CrawlDeduplicator $crawlDeduplicator = null): CrawlResult`
- `fetchRendered(string $url, BrowserDriver $driver, array $options = []): Page`
- `snapshotStore(): SnapshotStore`

## HTTP

- `HElturkey\WebTrawler\Options\FetchOptions`: immutable request and security options.
- `HElturkey\WebTrawler\Response\FetchResponse`: normalized transport response value.
- `HElturkey\WebTrawler\Contracts\Http\TransportInterface`: required contract for custom blocking transports.
- `HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface`: optional concurrency capability.
- `HElturkey\WebTrawler\Contracts\Http\CancellableTransportInterface`: optional cancellation capability.
- `HElturkey\WebTrawler\Transport\Drivers\GuzzleTransport`: default async-capable HTTP transport.
- `HElturkey\WebTrawler\Transport\Drivers\RnetTransport`: optional blocking native transport.
- `HElturkey\WebTrawler\Transport\Drivers\ImpityTransport`: optional blocking curl-impersonate transport.
- `HElturkey\WebTrawler\Retry\RetryPolicy` and `RetryingTransport`: retry configuration and decorator.
- `HElturkey\WebTrawler\Throttle\AutoThrottle`: per-host delay controller for spiders.
- `HElturkey\WebTrawler\Proxy\ProxyPool` and `ProxyTransport`: health-aware proxy selection and transport decorator.
- `HElturkey\WebTrawler\Session\Session`: cookie-jar-backed HTTP session.
- `HElturkey\WebTrawler\Policy\RequestPolicy`: URL and response security checks.

## Parsing

- `HElturkey\WebTrawler\Parsing\Page`: document-level CSS/XPath, title, text, HTML, URL, response, link resolution, network metadata, and adaptive selection API.
- `Element`: element text, accessible name, attributes, traversal, selector, and context API.
- `ElementCollection`: iterable collection with `first`, `last`, `all`, `map`, `each`, `count`, and `isEmpty`.

## Adaptive matching

- `ElementSnapshot`: serialized structural/context evidence.
- `AdaptiveMatcher`: candidate search and scoring.
- `AdaptiveScoringProfile`: configurable supported signal weights.
- `AdaptiveResult`, `AdaptiveMatch`, `AdaptiveSelection`: result and selection objects.
- `SnapshotStore`: storage contract; built-ins are `InMemorySnapshotStore` and `JsonSnapshotStore`.

## Crawling

- `Spider`: base class with start requests, parsing, crawl limits, concurrency, retry and throttle configuration.
- `SpiderRequest`: GET/POST crawl request value.
- `SpiderItem`: item value.
- `SpiderRunner`: crawl scheduler/executor.
- `CrawlDeduplicator`: optional exact URL/content fingerprint store; `SQLiteCrawlDeduplicator` uses the internal Bloom filter as a lookup hint only.
- `BloomFilter`: internal compact membership hint; positive results are never authoritative.
- `CrawlResult`: items, URLs, errors, counters, interrupted/resumed flags.
- `JsonCrawlStateStore`: JSON checkpoint storage.
- `DownloaderMiddleware` and `ItemPipeline`: extension contracts.

## Browser

- `BrowserDriver`: browser-driver contract.
- `PlaywrightBrowserDriver`: optional Chromium implementation.
- `BrowserRequestGuard`, `NetworkMetadata`, and `NetworkRequestMatcher`: guard and network observation helpers.
- `BrowserSessionState`: persistent cookies/local-storage state.

## CLI, MCP, Laravel

- CLI executables: `vendor/bin/scraper` and `vendor/bin/scraper-mcp`.
- MCP server: `HElturkey\WebTrawler\Mcp\McpServer`.
- Laravel provider: `HElturkey\WebTrawler\Laravel\ScraperServiceProvider`.
- Laravel facade: `HElturkey\WebTrawler\Laravel\Facades\Scrape`.

## Exports and errors

Exporters: `JsonExporter`, `JsonlExporter`, `CsvExporter`, `XmlExporter`; streaming item pipeline: `JsonlAppendPipeline`.

Relevant exception types include `ScraperException`, `NetworkException`, `UnsafeUrlException`, `HostNotAllowedException`, `HttpStatusException`, `ResponseTooLargeException`, `ProxyPoolExhaustedException`, and `RequestCancelledException`.

## Compatibility guidance

Use documented contracts (`HElturkey\WebTrawler\Contracts\Http\TransportInterface`, `HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface`, `HElturkey\WebTrawler\Contracts\Http\CancellableTransportInterface`, `BrowserDriver`, `SnapshotStore`, `DownloaderMiddleware`, `ItemPipeline`) for extensions. Avoid depending on internal implementation classes that are not listed as public entry points. Review changelog and integration tests when upgrading major/minor versions.
