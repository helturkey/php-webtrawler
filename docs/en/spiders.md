# Spiders and resumable crawling

A spider turns a page parser into a controlled crawl. Extend `Spider`, implement `parse()`, and yield items and next requests.

## Minimal spider

```php
<?php

declare(strict_types=1);

use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderItem;
use HElturkey\WebTrawler\Spider\SpiderRequest;

require __DIR__ . '/../vendor/autoload.php';

$spider = new class () extends Spider {
    protected array $startUrls = ['https://example.com/books'];
    protected array $allowedHosts = ['example.com'];
    protected int $maxPages = 100;
    protected int $concurrency = 4;

    public function parse(Page $page, SpiderRequest $request): iterable
    {
        foreach ($page->css('.book-card') as $card) {
            yield new SpiderItem([
                'title' => $card->css('h2')->first()?->text(),
                'url' => $card->css('a')->first()?->attr('href'),
            ]);
        }

        foreach ($page->css('a.next-page') as $link) {
            $href = $link->attr('href');
            if ($href !== null) {
                yield SpiderRequest::get($page->resolve($href));
            }
        }
    }
};

$scraper = Scraper::create();
$result = $scraper->crawl($spider);

foreach ($result->items as $item) {
    echo json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
}
```

The default `startRequests()` yields GET requests from `startUrls`. Override it for custom initial methods, headers, or payloads. `parse(Page, SpiderRequest)` returns an iterable of `SpiderRequest`, `SpiderItem`, or item arrays.

## Crawl settings

| Setting | Default | Meaning |
|---|---:|---|
| `startUrls` | `[]` | Initial URLs |
| `allowedHosts` | `[]` | Spider host allow-list; empty means unrestricted by this list |
| `maxPages` | `1000` | Maximum seed/request scheduling budget enforced by the runner |
| `maxDepth` | `10` | Maximum discovered depth |
| `requestDelayMs` | `0` | Minimum per-host request-start delay |
| `concurrency` | `1` | Concurrent downloads; values above 1 require async-capable transport |
| `collectItems` | `true` | Keep produced items in `CrawlResult::$items` |
| `retryPolicy` | `null` | Optional non-blocking spider retry policy |
| `autoThrottle` | `null` | Optional per-host delay controller |

`configure()` allows selected run-time settings. Unknown setting names are rejected.

## Request types

`SpiderRequest::get($url, $options)` creates a GET request. `SpiderRequest::post($url, $data, $options)` carries form data separately from inherited `FetchOptions` so scraper policy remains applicable. The request deduplication key includes request method and payload identity; distinct POST payloads must not collapse into one request.

A request-specific proxy is not persisted in a checkpoint because it may contain credentials. Use a configured `ProxyTransport` pool or resolve a safe proxy reference when resuming.

## Concurrency and transport compatibility

Guzzle is async-capable and supports concurrent spider downloads. `ProxyTransport` can preserve async capability when its wrapped transport supports it. Rnet, Impity, and `RetryingTransport` are blocking; spider concurrency must remain `1` when using those blocking transports. The spider runner applies its own non-blocking retry scheduler when used with an async transport; do not wrap an async crawl transport in the blocking retry wrapper.

## Retries and AutoThrottle

```php
use HElturkey\WebTrawler\Http\AutoThrottle;
use HElturkey\WebTrawler\Http\RetryPolicy;

$spider->configure([
    'retryPolicy' => new RetryPolicy(
        maxAttempts: 3,
        baseDelayMs: 500,
        maxDelayMs: 30_000,
    ),
    'autoThrottle' => new AutoThrottle(
        targetConcurrency: 1.0,
        startDelayMs: 1000,
        minDelayMs: 250,
        maxDelayMs: 10_000,
    ),
]);
```

Retries apply to configured methods (GET, HEAD, OPTIONS by default), retryable statuses (408, 425, 429, 500, 502, 503, 504 by default), and transient network failures. `Retry-After` is respected when enabled. Do not retry non-idempotent actions unless your application has an idempotency mechanism.

AutoThrottle adapts delay by host using observed latency and status. It is a politeness control, not a throughput guarantee; its delay can pause request scheduling. Use conservative settings and obey target-site terms and rate limits.

## Middleware and pipelines

A `DownloaderMiddleware` can adjust a request before fetching, transform a page after fetching, or handle an error. An `ItemPipeline` receives each item and may return a transformed item or `null` to drop it. Pipelines are appropriate for validation, normalization, persistence, and streaming output.

For large crawls, set `collectItems=false` and persist items through an `ItemPipeline` such as `JsonlAppendPipeline` to avoid retaining every item in memory.

## Resumable crawls

Use `JsonCrawlStateStore`:

```php
use HElturkey\WebTrawler\Spider\JsonCrawlStateStore;

$state = new JsonCrawlStateStore(__DIR__ . '/var/books.state.json');
$result = $scraper->crawl($spider, state: $state);
```

The runner checkpoints periodically (every 20 completed responses) and when it exits. State includes queued/deduplication data and counters, not the full item collection or accumulated error list. On resume, current-run `items` contains only items produced by that invocation; counters such as scheduled/completed work include prior checkpoint progress.

**Delivery semantics are at-least-once, not exactly-once.** After an abrupt process failure, an in-flight request may be replayed. Make pipelines idempotent (for example, upsert by a stable source key) and do not perform irreversible side effects without deduplication. JSON state accepts only supported JSON-safe request data. Unsupported extras, resources/streams, binary/non-UTF-8 values, and explicit per-request proxies are rejected rather than silently discarded.

Protect the state directory and file. The state file is private (0600); use a private parent directory and do not store secrets or raw credentials in item data.

## Graceful stop

The CLI can catch Ctrl-C / SIGTERM and save resumable state when `ext-pcntl` is available. The library runner also accepts a `shouldStop` callback:

```php
$result = $scraper->crawl(
    $spider,
    state: $state,
    shouldStop: static fn (): bool => $shutdownRequested,
);
```

Check `CrawlResult::$interrupted` and resume from the same state store. A clean stop is not a proof that all remote requests completed.

## CrawlResult

The result contains `items`, `visitedUrls`, redacted `errors`, `requestsScheduled`, `requestsCompleted`, `requestsRetried`, `interrupted`, `resumed`, and `itemsCount`. If `collectItems` is false, `items` is empty while `itemsCount` still reports items produced during that run.
