# Transports, retries and proxies

## Transport overview

| Transport | Package/runtime | Blocking model | Notes |
|---|---|---|---|
| Guzzle | Core dependency; concurrency requires ext-curl | Async-capable when internally configured with curl-multi | Default transport; injected opaque clients are blocking |
| Amp | Optional `amphp/http-client` 5 (proxies also need `amphp/http-tunnel`) | Async-capable | Revolt event loop; concurrent spiders; no proxy without `amphp/http-tunnel` |
| php-rnet | Optional native extension | Blocking | Browser-oriented TLS/HTTP fingerprint options; concurrency 1 |
| Impity | Optional `dekor/php-impity` and matching `curl-impersonate` binary | Blocking | curl-impersonate-backed PSR-18 transport; concurrency 1 |

Choose a transport deliberately. Fingerprint similarity does not guarantee that a site will accept a request, and browser-like TLS/HTTP behaviour is not a replacement for application-level authentication or permission.

## Guzzle (default)

```php
use HElturkey\WebTrawler\Transport\Drivers\GuzzleTransport;
use HElturkey\WebTrawler\Scraper;

$scraper = Scraper::create(transport: new GuzzleTransport());
```

The default-constructed Guzzle transport is concurrent when `ext-curl` is installed and it owns a curl-multi handler. A `GuzzleTransport` built around an injected `ClientInterface` is deliberately blocking because its handler lifecycle cannot be driven safely by this adapter. Security-sensitive settings are supplied through `HElturkey\WebTrawler\Options\FetchOptions` and enforced by the scraper/transport pipeline.

## php-rnet

Install/build the compatible php-rnet extension for the exact PHP ABI and operating-system architecture used by the application. Then select `RnetTransport`:

```php
use HElturkey\WebTrawler\Transport\Drivers\RnetTransport;
use HElturkey\WebTrawler\Scraper;

$transport = new RnetTransport(emulation: 'CHROME_136');
$scraper = Scraper::create(transport: $transport);
```

Rnet is blocking. Use spider concurrency `1`. The native extension may buffer a response internally, so the scraper's configured body limit should not be interpreted as a guarantee about the extension's peak internal memory use.

## Impity and curl-impersonate

Install the Composer package and a compatible executable. Configure the executable path before constructing the transport:

```bash
export IMPITY_CURL_IMPERSONATE_PATH=/usr/local/bin/curl-impersonate
```

```php
use HElturkey\WebTrawler\Transport\Drivers\ImpityTransport;
use HElturkey\WebTrawler\Scraper;

$scraper = Scraper::create(transport: new ImpityTransport());
```

The binary must be executable and compatible with the `dekor/php-impity` package version. Verify its version and architecture in deployment. The integration CI pins curl-impersonate `2.3.1`; pin and test the same combination in production images. Impity is blocking; use spider concurrency `1`.

## Custom transports

Implement `HElturkey\WebTrawler\Contracts\Http\TransportInterface` for blocking request/response transports. Implement `HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface` as well when the transport can truly dispatch and complete requests asynchronously. A transport must not claim async capability while serializing requests internally.

Custom transports are part of the security boundary. They must validate every redirect destination, preserve TLS verification, enforce host/private-network/body policies, strip origin-bound credentials on cross-origin redirects, and report the effective URL correctly. If a transport cannot enforce an option, fail explicitly or document the limitation; do not silently ignore it.

## RetryPolicy

`RetryPolicy` defaults:

- `maxAttempts`: 3 total attempts, including the first attempt;
- base delay: 500 ms;
- maximum delay: 30 seconds;
- exponential multiplier: 2;
- equal jitter;
- retry statuses: 408, 425, 429, 500, 502, 503, 504;
- retry methods: GET, HEAD, OPTIONS;
- `Retry-After` support enabled.

Use `RetryPolicy::none()` or `maxAttempts: 1` to disable retries. Retries are for transient failures, not for bypassing authentication, robots policies, rate limits, or permanent errors. Respect target-site policy and avoid replaying non-idempotent requests without application-level idempotency.

`RetryingTransport` is a blocking wrapper for one-off requests. The spider runner has its own retry scheduler; do not wrap an async spider transport with `RetryingTransport`, because the wrapper is blocking and can disable concurrency or cause retries at two layers.

## ProxyPool and ProxyTransport

`ProxyPool` supports `round_robin`, `random`, and `least_failures`. It tracks consecutive/total failures and successes, cools down unhealthy proxies, and permits one half-open probe at a time after cooldown.

```php
use HElturkey\WebTrawler\Proxy\ProxyPool;
use HElturkey\WebTrawler\Proxy\ProxyTransport;
use HElturkey\WebTrawler\Transport\Drivers\GuzzleTransport;

$pool = new ProxyPool(
    proxies: [
        getenv('SCRAPER_PROXY_ONE'),
        getenv('SCRAPER_PROXY_TWO'),
    ],
    strategy: ProxyPool::ROUND_ROBIN,
    maxConsecutiveFailures: 3,
    cooldownSeconds: 60,
);

$transport = new ProxyTransport(
    inner: new GuzzleTransport(),
    pool: $pool,
    allowDirectFallback: false,
);
```

The pool is an enforced route by default: a request that carries its own `proxy` option is rejected with an
`InvalidArgumentException` instead of silently bypassing the pool. Pass `allowExplicitProxyOverride: true` only when
every caller is trusted to choose its own proxy.

Remove any unset/empty environment entries before constructing the pool. A pool with no available proxy throws `ProxyPoolExhaustedException` unless direct fallback is explicitly enabled. Keep direct fallback disabled when proxy routing is mandatory. Proxy statistics mask credentials and omit query/path secrets.

The Laravel service provider configures a proxy pool for HTTP fetches and spider requests. The pool is **not** automatically used by Playwright; configure the browser's own proxy or enforce egress routing at the network layer.

## Transport compatibility rule

For concurrent crawls, use the default Guzzle transport with `ext-curl`, Amp, or another tested async-capable transport. An injected Guzzle client is blocking. Rnet and Impity are blocking integrations. Confirm the transport and proxy topology in integration tests before increasing concurrency.


Cancellation is optional. Implement `HElturkey\WebTrawler\Contracts\Http\CancellableTransportInterface` (which extends `HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface`) only when the transport can actively abandon in-flight work. A transport that does not support cancellation may implement `HElturkey\WebTrawler\Contracts\Http\AsyncTransportInterface` alone. Deliberate cancellation is represented by `RequestCancelledException` and must not be counted as a network or proxy failure.
