# Exporting and extension points

## Built-in exporters

The library includes JSON, JSONL, CSV, and XML exporters:

```php
use HElturkey\WebTrawler\Export\JsonExporter;
use HElturkey\WebTrawler\Export\JsonlExporter;
use HElturkey\WebTrawler\Export\CsvExporter;
use HElturkey\WebTrawler\Export\XmlExporter;

$items = [
    ['title' => 'Book A', 'price' => '12.00'],
    ['title' => 'Book B', 'price' => '15.00'],
];

(new JsonExporter())->export($items, __DIR__ . '/out/items.json', pretty: true);
(new JsonlExporter())->export($items, __DIR__ . '/out/items.jsonl');
(new CsvExporter())->export($items, __DIR__ . '/out/items.csv', headers: ['title', 'price']);
(new XmlExporter())->export($items, __DIR__ . '/out/items.xml');
```

Check the constructor/method signature in the installed version when using optional CSV parameters. CSV supports explicit headers, optional UTF-8 BOM for spreadsheet compatibility, and opt-in formula neutralization. If headers are omitted, the first non-empty row defines the columns. For dynamic data, define a stable header order rather than relying on unpredictable row keys.

XML export uses `ext-xmlwriter`, flushes after each item, limits recursion depth, sanitizes element names, and traverses iterators without first materializing them as arrays. Do not treat XML-safe names or CSV formula neutralization as a complete data-loss-prevention system; validate and classify your own data before export.

All file exporters accept an `iterable`, encode and write one item at a time, and atomically replace the destination only after a successful flush. Temporary files are created exclusively in the destination directory and use owner-only `0600` permissions; the final file inherits those permissions. A failed export leaves the previous destination untouched and removes its temporary file. Keep the destination directory writable only by the application, and avoid public web roots for sensitive output. Streaming bounds exporter overhead to the current item and its encoded representation; it cannot reduce memory already used by an input array or by a single exceptionally large item.

## JSONL append pipeline

`JsonlAppendPipeline` writes items as they are produced. Combine it with `Spider::collectItems=false` for large crawls to avoid keeping the entire item set in memory. Make output processing idempotent when using resumable crawls because requests may be replayed after a crash.

## Downloader middleware

Implement `HElturkey\WebTrawler\Contracts\DownloaderMiddleware` to participate in the fetch lifecycle:

- before fetch: inspect or transform a `SpiderRequest`;
- after fetch: inspect/transform a `Page`;
- on error: record or translate the failure.

Middleware must not weaken the scraper's enforced security policy. Never log credentials, raw bodies, or unredacted sensitive URLs.

## Item pipelines

Implement `HElturkey\WebTrawler\Contracts\ItemPipeline`. Its `process(array $item): ?array` method returns the transformed item or `null` to drop it. Use pipelines for schema validation, normalization, persistence, deduplication, and output streaming.

For resumable crawls, prefer idempotent persistence keyed by a stable source identifier. A pipeline should not assume a page is delivered exactly once.

## Custom HTTP transport

Implement `HElturkey\WebTrawler\Contracts\Http\TransportInterface` for blocking transports and `HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface` only when the implementation truly supports asynchronous requests. A custom transport must preserve the package's documented transport contract, including:

- redirect hop checks against host/private-network policy;
- response body limits while reading, not only after buffering;
- TLS verification and origin-aware sensitive-header handling;
- effective URL reporting;
- timeouts and connection errors represented consistently;
- no silent fallback from a required proxy to direct networking.

Add adapter-specific integration tests against a local HTTP server before production use. A passing interface/type check is not sufficient proof of network-policy enforcement. The core validates the browser navigation URL and final URL, but cannot inspect or block every browser subrequest made by an arbitrary third-party driver.

## SnapshotStore

Implement `SnapshotStore` to store `ElementSnapshot` values. The built-in in-memory store is process-local. The JSON store is local single-host persistence. Distributed deployments should use a storage system with transactional or concurrency semantics appropriate to their topology.

## Adding your own transport or browser driver

Nothing in `Fetcher`, `Downloader` or `SpiderRunner` has to change. The pieces are separate:

| Piece | Contract | Your job |
| --- | --- | --- |
| Transport | `TransportInterface::send()` | Return a `HElturkey\WebTrawler\Response\FetchResponse`. Enough for `Scraper::fetch()` and sequential spiders. |
| Concurrency | `HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface` (`sendAsync()` returns a `Concurrency\Pending`, `tick()` advances I/O) | Optional. Use any mechanism: curl-multi, Revolt/Amp, ReactPHP, workers. |
| Browser | `BrowserDriver::render()` | Return a `BrowserResult`; `BrowserTransport` adapts it to a transport. The driver must enforce policy for every subrequest; use network egress isolation for untrusted targets. |
| Parsing | `ResponseParser` | Optional. Default is `HtmlPageParser` (HTML5). |

```php
$registry = TransportRegistry::withDefaults();
$registry->register('patchright', fn (array $config) => new BrowserTransport(new PatchrightDriver()));
```

The CLI (`--transport patchright`, pass the registry to `Application`) and Laravel
(`config('scraper.transport')`; register through `afterResolving(TransportRegistry::class, ...)`) resolve names
through the registry. The spider runner handles downloads in completion order: it calls `tick()`, takes whatever
settled, refills free slots, then parses on the calling thread.
