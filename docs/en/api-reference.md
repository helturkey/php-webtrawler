# API reference

This page summarizes the supported entry points. The installed source and PHP signatures remain authoritative for exact optional argument types.

## Main facade

`HElturkey\WebTrawler\Scraper`

- `Scraper::create(...)`: factory accepting optional HTTP transport, snapshot store, adaptive matcher, default fetch options, enforced-default-security flag, and default retry policy.
- `fetch(string $url, ?FetchOptions $options = null): Page`
- `get(string $url, ?FetchOptions $options = null): Page`
- `post(string $url, array $data = [], ?FetchOptions $options = null): Page`
- `session(): Session`
- `crawl(Spider $spider, array $middlewares = [], array $pipelines = [], ?JsonCrawlStateStore $state = null, ?callable $shouldStop = null): CrawlResult`
- `fetchRendered(string $url, BrowserDriver $driver, array $options = []): Page`
- `snapshotStore(): SnapshotStore`

## HTTP

- `HElturkey\WebTrawler\Http\FetchOptions`: immutable request and security options.
- `Fetcher`: internal request orchestration used by `Scraper`.
- `GuzzleTransport`: default async-capable HTTP transport.
- `RnetTransport`: optional blocking native transport.
- `ImpityTransport`: optional blocking curl-impersonate transport.
- `RetryPolicy`: retry decision and delay configuration.
- `RetryingTransport`: blocking retry wrapper for one-off transports.
- `AutoThrottle`: per-host delay controller for spiders.
- `ProxyPool`: health-aware proxy selection.
- `ProxyTransport`: transport decorator that routes requests through a pool.
- `Session`: cookie-jar-backed HTTP session.

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

Relevant exception types include `ScraperException`, `NetworkException`, `UnsafeUrlException`, `HostNotAllowedException`, `HttpStatusException`, `ResponseTooLargeException`, and `ProxyPoolExhaustedException`.

## Compatibility guidance

Use documented contracts (`HttpTransport`, `AsyncHttpTransport`, `BrowserDriver`, `SnapshotStore`, `DownloaderMiddleware`, `ItemPipeline`) for extensions. Avoid depending on internal implementation classes that are not listed as public entry points. Review changelog and integration tests when upgrading major/minor versions.
