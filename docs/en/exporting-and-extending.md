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

Check the constructor/method signature in the installed version when using optional CSV parameters. CSV supports explicit headers, optional UTF-8 BOM for spreadsheet compatibility, and formula neutralization. For dynamic data, define a stable header order rather than relying on unpredictable row keys.

XML export uses `ext-xmlwriter`, limits recursion depth, and sanitizes element names. Do not treat XML-safe names or CSV formula neutralization as a complete data-loss-prevention system; validate and classify your own data before export.

File exporters use atomic temporary-file replacement where implemented. Write to directories owned by the application, set restrictive permissions, and avoid public web roots for sensitive output.

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

Implement `HttpTransport` for blocking transports and `AsyncHttpTransport` only when the implementation truly supports asynchronous requests. A custom transport must preserve the package's documented transport contract, including:

- redirect hop checks against host/private-network policy;
- response body limits while reading, not only after buffering;
- TLS verification and origin-aware sensitive-header handling;
- effective URL reporting;
- timeouts and connection errors represented consistently;
- no silent fallback from a required proxy to direct networking.

Add adapter-specific integration tests against a local HTTP server before production use. A passing interface/type check is not sufficient proof of network-policy enforcement.

## SnapshotStore

Implement `SnapshotStore` to store `ElementSnapshot` values. The built-in in-memory store is process-local. The JSON store is local single-host persistence. Distributed deployments should use a storage system with transactional or concurrency semantics appropriate to their topology.
