# Services and transports reference

This page documents every service in the package: what it is for, how to build it, and a short example. Namespaces
are under `HElturkey\WebTrawler\`. The layers are separate on purpose, so you can replace any one of them without
touching the others.

```
Scraper ──► Fetcher ──► Downloader ──► RequestPolicy   (URL, host, private-network, size rules)
               │            └──────► TransportInterface  (Guzzle | Amp | Rnet | Impity | Browser | yours)
               └──► ResponseParser ──► Page ──► Element / ElementCollection
Scraper::crawl ──► SpiderRunner ──► Fetcher + Spider + middlewares + pipelines + state + deduplicator
```

## 1. Entry point

| Service | Namespace | Role |
|---|---|---|
| `Scraper` | root | Facade: `fetch`, `get`, `post`, `session`, `crawl`, `fetchRendered`, `snapshotStore` |
| `Fetching\Fetcher` | `Fetching` | Download + parse. Use it when you need `fetchAsync()` or the two halves separately |
| `Fetching\Downloader` | `Fetching` | URL policy → transport → response checks. Never parses |
| `Fetching\BrowserFetcher` | `Fetching` | Renders through any `BrowserDriver`, then parses with the same parser |
| `Policy\RequestPolicy` | `Policy` | Resolves per-request options against the enforced defaults |

```php
use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Scraper;

$scraper = Scraper::create(
    defaultOptions: new FetchOptions(timeout: 15, blockPrivateNetworks: true, allowedHosts: ['example.com']),
    enforceDefaultSecurity: true,   // per-request options can only be stricter than these defaults
);
$page = $scraper->get('https://example.com/');
```

## 2. Request options and policy

* **`Options\FetchOptions`** – immutable. Timeouts, TLS, redirects, size limit (default 20 MiB), host allow-list,
  private-network blocking, charset. `tightenedBy($policy)` returns the stricter combination; `withExtraOption()`
  and `withAllowedHosts()` return copies.
* **`Policy\RequestPolicy`** – used by `Downloader`. `prepareUrl()` normalizes the URL and applies the host list and
  `UrlGuard`; `verify()` re-checks body size and the *effective* URL after redirects.
* **`Policy\UrlNormalizer`** – accepts only well-formed http(s) URLs; lowercases scheme/host, converts IDN hosts,
  drops default ports, removes `.`/`..` segments and makes an empty path `/`.
* **`Policy\UrlGuard`** – rejects loopback, private, link-local, reserved and ambiguous numeric hosts, and names
  that do not resolve (fail closed). It cannot stop DNS rebinding; use egress rules for hostile input.
* **`Policy\HostPolicy`** – exact host or subdomain match. **`Policy\UrlOrigin`** compares scheme + host + port.

```php
use HElturkey\WebTrawler\Policy\UrlGuard;

UrlGuard::assertPublic('http://169.254.169.254/latest/meta-data/'); // throws UnsafeUrlException
```

## 3. Transports

All transports implement `Contracts\Http\TransportInterface::send()` and return a `Response\FetchResponse`.
Async-capable ones also implement `AsyncTransportInterface` (`supportsAsync()`, `sendAsync()`, `tick()`), and
`CancellableTransportInterface` adds `cancelAll()`.

| Transport | Class (`Transport\Drivers`) | Async | Needs | Typical use |
|---|---|:-:|---|---|
| Guzzle | `GuzzleTransport` | yes, with `ext-curl` and no injected client | core | Default; concurrent spiders |
| Amp | `AmpTransport` | yes | `amphp/http-client` 5 (+ `amphp/http-tunnel` for proxies) | Revolt event loop |
| php-rnet | `RnetTransport` | no | native extension | Browser-like TLS/HTTP2 fingerprints |
| Impity | `ImpityTransport` | no | `dekor/php-impity` + `curl-impersonate` | PSR-18 fingerprint transport |
| Browser | `BrowserTransport` | no | any `BrowserDriver` | JS-rendered pages through `Scraper::fetch()` |

Supported request options: `headers`, `timeout`, `connect_timeout`, `verify`, `allow_redirects`, `proxy`, `cookies`,
`query`, `form_params`, `json`, `body`, plus the policy keys `max_body_bytes`, `block_private_networks`,
`allowed_hosts`. Rnet and Impity reject `multipart`, `sink`, `stream` and `auth`.

Every bundled transport follows the same rules: redirects are followed by the transport so **each hop** is checked
against the host list and the private-network guard, credentials are dropped on cross-origin hops, the body limit is
enforced while reading where the backend allows it, and the effective URL is reported.

```php
use HElturkey\WebTrawler\Transport\Drivers\AmpTransport;
use HElturkey\WebTrawler\Transport\Drivers\GuzzleTransport;

$scraper = Scraper::create(transport: new GuzzleTransport());   // default
$scraper = Scraper::create(transport: new AmpTransport());      // Revolt based
```

### TransportRegistry

`Transport\TransportRegistry` maps a name to a factory so configuration (CLI `--transport`, Laravel
`config('scraper.transport')`) can select transports. `withDefaults()` registers `guzzle`, `amp`, `rnet`, `impity`.

```php
use HElturkey\WebTrawler\Transport\TransportRegistry;

$registry = TransportRegistry::withDefaults();
$registry->register('internal-proxy', fn (array $config) => new MyCompanyTransport());
$transport = $registry->create('internal-proxy');
```

Names are lowercase letters, digits, `-` and `_`, starting with a letter.

## 4. Decorators: retries, proxies, throttling

* **`Retry\RetryPolicy`** – pure decision logic (attempts, exponential back-off, jitter `none|equal|full`,
  retry statuses, idempotent methods, `Retry-After`). `RetryPolicy::none()` disables retries.
* **`Retry\RetryingTransport`** – blocking wrapper for one-off fetches. Do not use it around an async crawl
  transport; the spider runner has its own non-blocking retry scheduler.
* **`Proxy\ProxyPool`** – `round_robin`, `random`, `least_failures`; per-proxy circuit breaker with cooldown and a
  single half-open probe; credentials are masked in `stats()`.
* **`Proxy\ProxyTransport`** – routes each request through the pool and reports success/failure back. The pool is an
  **enforced route**: a request carrying its own `proxy` is rejected unless you pass
  `allowExplicitProxyOverride: true`. Direct fallback when the pool is exhausted needs `allowDirectFallback: true`.
* **`Throttle\AutoThrottle`** – per-host delay derived from latency and target concurrency; errors never speed it up.

```php
use HElturkey\WebTrawler\Proxy\{ProxyPool, ProxyTransport};
use HElturkey\WebTrawler\Retry\{RetryPolicy, RetryingTransport};
use HElturkey\WebTrawler\Transport\Drivers\GuzzleTransport;

$pool = new ProxyPool([getenv('PROXY_A'), getenv('PROXY_B')], ProxyPool::LEAST_FAILURES, 3, 60);
$transport = new RetryingTransport(
    new ProxyTransport(new GuzzleTransport(), $pool),
    new RetryPolicy(maxAttempts: 4, baseDelayMs: 300, jitter: RetryPolicy::JITTER_FULL),
);
```

## 5. Concurrency primitive

`Concurrency\Pending` is a settle-once result with `resolve()`, `reject()`, `isSettled()`, `value()`, `error()`,
`onSettled()` and `then()`. It is deliberately not a promise library: the transport settles it from its own I/O,
and the consumer reads it after `tick()` returned. Settling twice is ignored.

```php
use HElturkey\WebTrawler\Concurrency\Pending;

$pending = new Pending();
$mapped = $pending->then(fn ($response) => $response->status);
$pending->resolve($fetchResponse);
echo $mapped->value();
```

## 6. Parsing

* **`Contracts\ResponseParser`** / **`Parsing\HtmlPageParser`** – turns a `FetchResponse` into a `Page`
  (HTML5 tree via Symfony; charset order: explicit option, BOM, header, `<meta>`, valid UTF-8).
* **`Parsing\Page`** – `css()`, `xpath()`, `title()`, `text()`, `html()`, `url()`, `resolve()`, `response()`,
  `networkRequests()`, `apiResponses()`, `failedRequests()`, `remember()`, `adaptive()`.
* **`Parsing\Element` / `ElementCollection`** – traversal and attribute access; collections are countable and iterable.
* **`Response\FetchResponse`** – `status`, `headers`, `body`, `requestedUrl`, `effectiveUrl`, `durationMs`, `meta`;
  helpers `isSuccessful()`, `header()`, `json()`, `contentHash()`.

```php
$rows = $page->css('table.prices tr')->map(static fn ($tr): array => [
    'name'  => $tr->css('td.name')->first()?->text(),
    'price' => $tr->css('td.price')->first()?->text(),
]);
```

## 7. Sessions

`Session\Session` keeps a cookie jar across requests (`get`, `post`, `fetch`, `cookies`). It does not model browser
storage or tabs. For browser state use `BrowserSessionState` (see [Browser rendering](browser.md)).

## 8. Adaptive matching and snapshot stores

`AdaptiveMatcher`, `AdaptiveScoringProfile`, `ElementSnapshot`, `AdaptiveSelection`, `AdaptiveResult`,
`AdaptiveMatch` implement ranked similarity matching (see [Adaptive selectors](adaptive-selectors.md)). Snapshots
are stored through `Contracts\SnapshotStore`:

| Store | Scope | Notes |
|---|---|---|
| `InMemorySnapshotStore` | one process | Default |
| `JsonSnapshotStore` | one host, local disk | Lock + atomic replace |
| `SQLiteSnapshotStore` | several processes, one machine | Single UPSERT per write, WAL, lazy connection reopened after fork |

```php
$scraper = Scraper::create(snapshotStore: new SQLiteSnapshotStore(__DIR__ . '/var/snapshots.sqlite'));
```

## 9. Crawling services

* **`Spider\Spider`** – subclass and implement `parse()`; settings `startUrls`, `allowedHosts`, `maxPages`,
  `maxDepth`, `requestDelayMs`, `concurrency`, `collectItems`, `retryPolicy`, `autoThrottle`.
* **`Spider\SpiderRunner`** – bounded parallel downloads handled in completion order; parsing, middlewares and
  pipelines always run on the calling thread.
* **`Spider\RequestFingerprint`** – SHA-256 request identity (method, canonical URL without fragment, payload and
  per-request options).
* **`Spider\JsonCrawlStateStore`** – atomic 0600 checkpoint for resumable crawls (at-least-once delivery).
* **`Contracts\CrawlDeduplicator`** / **`Storage\SQLiteCrawlDeduplicator`** – on-disk visited-URL and exact-content
  set per crawl scope. SQLite's primary key is authoritative; the Bloom filter only skips lookups for definite misses.
  `purgeOlderThan($seconds)` removes rows left behind by a crawl that was killed.
* **`Contracts\DownloaderMiddleware`**, **`Contracts\ItemPipeline`**, **`Pipeline\JsonlAppendPipeline`** – extension points.

```php
$deduplicator = new SQLiteCrawlDeduplicator(__DIR__ . '/var/frontier.sqlite', expectedUrls: 2_000_000);
$result = $scraper->crawl($spider, state: $state, crawlDeduplicator: $deduplicator);
printf("%d pages, %d byte-identical duplicates\n", $result->requestsCompleted, $result->duplicateContentResponses);

// Nightly maintenance job: drop frontier rows older than 3 days.
$deduplicator->purgeOlderThan(3 * 86400);
```

### Crawling-toolkit services

| Service | Role |
|---|---|
| `Policy\RobotsTxt` / `Policy\RobotsPolicy` | RFC 9309 parser and per-origin cached policy (`Spider::$respectRobotsTxt`) |
| `Spider\LinkExtractor`, `Spider\Rule`, `Spider\CrawlSpider` | Declarative link following |
| `Spider\SitemapSpider`, `Parsing\SitemapParser` | Crawl from sitemaps (index, gzip, same-host rule) |
| `Response\BlockDetector`, `Response\BlockSignal` | Detect anti-bot challenges and rate limits (never solves them) |
| `Transport\CachingTransport` | Development cache with offline replay |
| `Parsing\MarkdownConverter` | HTML to Markdown behind `Page::markdown()` |
| `Contracts\BatchCrawlDeduplicator` | One-transaction URL marking (`SQLiteCrawlDeduplicator`) |
| `Cli\Shell` | Interactive exploration shell |
| `Scraper::stream()`, `crawl(sessions: [...])` | Streaming items; named transports per request |

See [crawling toolkit](crawling-toolkit.md).

## 10. Browser services

`Contracts\BrowserDriver` (one method, `render()`), `Browser\BrowserResult`, `Browser\PlaywrightBrowserDriver`,
`Transport\Drivers\BrowserTransport`, `Browser\BrowserRequestGuard`, `Browser\NetworkMetadata`,
`Browser\NetworkRequestMatcher`, `Browser\BrowserSessionState`, and `Policy\BrowserOptionsResolver` (turns scraper
policy into the millisecond-based browser option contract). `BrowserFetcher` renders **the canonical URL that passed
the policy checks**, never the raw input string.

## 11. Exporters

`JsonExporter`, `JsonlExporter`, `CsvExporter`, `XmlExporter` stream items through `Export\AtomicFile`: a temporary
file is created exclusively with mode 0600, fsynced, then renamed over the target, so a failed export never
leaves a truncated file.

```php
(new CsvExporter())->export($items, __DIR__ . '/out/books.csv', headers: ['title', 'price'], utf8Bom: true, neutralizeFormulas: true);
```

## 12. Front ends

* **CLI** (`bin/scraper`): `fetch`, `crawl`, `version`. `--transport` accepts any name in the registry.
  `crawl --dedupe-db FILE` keeps the visited-URL set in SQLite.
* **MCP server** (`bin/scraper-mcp`): tools `fetch_page` and `extract`, private-network blocking on by default.
* **Laravel**: `ScraperServiceProvider`, `Scrape` facade, `scraper:fetch`; transports are resolved through the
  container's `TransportRegistry`.

## 13. Exceptions

| Exception | Meaning | Retried? |
|---|---|:-:|
| `ScraperException` | Base class | – |
| `NetworkException` (`TransientFailure`) | Timeout, reset, refused, body read error | yes |
| `ProxyPoolExhaustedException` | All proxies cooling down | yes |
| `HttpStatusException` | Status not accepted | by status |
| `UnsafeUrlException` | Private/loopback/ambiguous target | no |
| `HostNotAllowedException` | Host outside the allow-list | no |
| `ResponseTooLargeException` | Body above `maxBodyBytes` | no |
| `RequestCancelledException` | Deliberate cancellation, not a failure | no |

---

# Writing your own transport

You never modify `Fetcher`, `Downloader` or `SpiderRunner`. Pick the smallest contract that fits.

## A. Blocking transport (enough for `fetch`, sessions and sequential spiders)

```php
<?php

declare(strict_types=1);

use HElturkey\WebTrawler\Contracts\Http\TransportInterface;
use HElturkey\WebTrawler\Exception\NetworkException;
use HElturkey\WebTrawler\Exception\ResponseTooLargeException;
use HElturkey\WebTrawler\Policy\HostPolicy;
use HElturkey\WebTrawler\Policy\UrlGuard;
use HElturkey\WebTrawler\Response\FetchResponse;

final class CurlTransport implements TransportInterface
{
    public function send(string $method, string $url, array $options = []): FetchResponse
    {
        $started = hrtime(true);
        $limit = $options['max_body_bytes'] ?? null;

        // Enforce policy yourself: the Downloader checks the first URL, but only the transport sees redirects.
        HostPolicy::assert($url, $options['allowed_hosts'] ?? []);
        if (($options['block_private_networks'] ?? false) === true) {
            UrlGuard::assertPublic($url);
        }

        $headers = [];
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_FOLLOWLOCATION => false,            // redirects come back as 3xx; a production transport loops here and validates every hop
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => ($options['verify'] ?? true) !== false,
            CURLOPT_TIMEOUT_MS => (int) (($options['timeout'] ?? 30) * 1000),
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[trim($name)][] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$body, $limit): int {
                $body .= $chunk;
                // Stop while reading, not after buffering the whole body.
                return $limit !== null && strlen($body) > $limit ? 0 : strlen($chunk);
            },
        ]);

        if (curl_exec($curl) === false) {
            $error = curl_errno($curl) === CURLE_WRITE_ERROR && $limit !== null
                ? ResponseTooLargeException::forLimit($limit, $url)
                : new NetworkException('Request failed: ' . curl_error($curl));
            curl_close($curl);
            throw $error;
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return new FetchResponse($status, $headers, $body, $url, $url, (hrtime(true) - $started) / 1e6);
    }
}

$scraper = Scraper::create(new CurlTransport());
```

Checklist: validate every redirect hop, keep TLS verification, enforce the body limit while reading, strip
`Authorization` and cookies on cross-origin redirects, report the final URL in `effectiveUrl`, throw
`NetworkException` for transient errors, and reject options you cannot honour instead of ignoring them.

## B. Asynchronous transport (concurrent spiders)

Implement `AsyncTransportInterface` and settle `Pending` objects from your own I/O. `sendAsync()` must return at
once; `tick()` must advance work and return when something settled, nothing is in flight, or the timeout hint passed.

```php
use HElturkey\WebTrawler\Concurrency\Pending;
use HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface;

final class WorkerPoolTransport implements AsyncTransportInterface
{
    /** @var array<int, Pending> */
    private array $inflight = [];
    private int $next = 1;

    public function __construct(private readonly MyWorkerPool $pool) {}

    public function supportsAsync(): bool { return true; }

    public function send(string $method, string $url, array $options = []): FetchResponse
    {
        $pending = $this->sendAsync($method, $url, $options);
        while (!$pending->isSettled()) {
            $this->tick();
        }
        return $pending->value();            // rethrows the failure
    }

    public function sendAsync(string $method, string $url, array $options = []): Pending
    {
        $pending = new Pending();
        $id = $this->next++;
        $this->inflight[$id] = $pending;
        $this->pool->submit($id, $method, $url, $options);   // returns immediately
        return $pending;
    }

    public function tick(?float $timeoutSeconds = null): void
    {
        foreach ($this->pool->wait($timeoutSeconds) as [$id, $response, $error]) {
            $pending = $this->inflight[$id] ?? null;
            unset($this->inflight[$id]);
            $error === null ? $pending?->resolve($response) : $pending?->reject($error);
        }
    }
}
```

Never claim `supportsAsync() === true` while serializing requests internally. Add `CancellableTransportInterface`
(`cancelAll()`) only if you can really abandon in-flight work, and reject abandoned results with
`RequestCancelledException` so they are not counted as proxy failures.

## C. Browser driver

Implement `BrowserDriver::render()` and return a `BrowserResult`. Wrap it in `BrowserTransport` to use it anywhere a
transport is accepted, or pass it to `Scraper::fetchRendered()`.

```php
use HElturkey\WebTrawler\Browser\BrowserResult;
use HElturkey\WebTrawler\Contracts\BrowserDriver;
use HElturkey\WebTrawler\Transport\Drivers\BrowserTransport;

final class PatchrightDriver implements BrowserDriver
{
    public function render(string $url, array $options = []): BrowserResult
    {
        // $options['timeout'] is in milliseconds; allowed_hosts and block_private_networks are YOUR job
        // for every sub-request, because the core can only check the first and the final URL.
        [$html, $finalUrl, $status] = $this->navigate($url, $options);

        return new BrowserResult(html: $html, requestedUrl: $url, finalUrl: $finalUrl, statusCode: $status);
    }
}

$scraper = Scraper::create(new BrowserTransport(new PatchrightDriver(), ['wait_until' => 'networkidle']));
```

`BrowserTransport` converts seconds to milliseconds and refuses non-GET methods rather than downgrading them.

## D. Make it selectable by name

```php
$registry->register('patchright', fn (array $config) => new BrowserTransport(new PatchrightDriver()));
// CLI:     bin/scraper fetch https://example.com --transport patchright   (pass the registry to Application)
// Laravel: $this->app->afterResolving(TransportRegistry::class, fn ($r) => $r->register('patchright', ...));
```

## E. Test it

Run the transport against a local HTTP server (see `tests/Support/LocalServer.php` and
`tests/Fixtures/router.php`): redirect to a private address, redirect to another host, an oversized chunked body, a
slow response and a cross-origin redirect with `Authorization`. A type check alone does not prove policy enforcement.
