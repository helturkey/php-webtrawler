# Fetching, sessions and parsing

## Fetch API

Create a scraper and use one of the convenience methods:

```php
$page = $scraper->fetch($url, $options); // generic request (GET by default)
$page = $scraper->get($url, $options);
$page = $scraper->post($url, $data, $options);
```

`Scraper::create()` accepts an optional `HttpTransport`, `SnapshotStore`, `AdaptiveMatcher`, default `FetchOptions`, `enforceDefaultSecurity` flag, and default `RetryPolicy`. Prefer named arguments so policy settings remain readable.

`fetch()` returns `HElturkey\WebTrawler\Parsing\Page`. HTTP errors are represented as transport/fetch exceptions or status data according to the path; a spider accepts only 2xx statuses by default.

## FetchOptions

`FetchOptions` is immutable. Use constructor arguments or `withAllowedHosts()` / `withExtraOption()` to create a modified copy.

| Option | Default | Meaning |
|---|---:|---|
| `headers` | `[]` | Request headers; values may be strings or string arrays |
| `timeout` | `30.0` | Overall request timeout in seconds; `0` means no limit |
| `connectTimeout` | `10.0` | Connection timeout in seconds; `0` means no limit |
| `proxy` | `null` | Proxy URL or supported proxy configuration array |
| `verifyTls` | `true` | Verify TLS certificates |
| `allowRedirects` | `true` | Follow HTTP redirects subject to redirect policy |
| `maxRedirects` | `10` | Maximum redirect hops |
| `extra` | `[]` | Advanced Guzzle-style transport options |
| `maxBodyBytes` | `20 MiB` | Abort a response when the configured body limit is exceeded; `null` disables this safeguard |
| `blockPrivateNetworks` | `false` | Refuse private, loopback, and link-local destinations |
| `encoding` | `null` | Explicit response charset; otherwise BOM, HTTP header, HTML meta, then UTF-8 detection is used |
| `allowedHosts` | `[]` | Exact hosts and their subdomains allowed for requests and redirect hops; empty means unrestricted by host allow-list |

A response exceeding `maxBodyBytes` raises `ResponseTooLargeException`. Keep a finite limit for untrusted targets.

## Parsing API

`Page` provides:

- `css(string $selector): ElementCollection`
- `xpath(string $expression): ElementCollection`
- `title(): ?string`
- `text(): string`
- `html(): string`
- `url(): string` (effective page URL)
- `resolve(string $url): string` for resolving relative links against the page URL
- `response(): ?FetchResponse`
- `networkRequests()`, `apiResponses()`, and `failedRequests()` for browser network metadata when present
- `remember(string $key, Element $element)` and `adaptive(string $key, ...)` for adaptive matching

`Element` exposes `text()`, `ownText()`, `accessibleName()`, `attr()`, `hasAttr()`, `tag()`, `attributes()`, `css()`, `xpath()`, `parent()`, `children()`, `siblings()`, and ancestor/sibling context helpers.

Use null-safe access for optional elements:

```php
$price = $page->css('.price')->first()?->text();
```

Treat text and attributes as untrusted data. Escape output for the destination context (HTML, SQL, shell, logs, or CSV); parsing does not make scraped content safe.

## Charset handling

When `encoding` is omitted, HTML decoding considers a byte-order mark, the HTTP `Content-Type` charset, HTML `<meta>` declarations, and valid UTF-8 fallback. Set `encoding` only when you know the correct source encoding. Incorrect explicit encoding overrides the normal detection order.

## Cookie sessions

`$scraper->session()` creates a session wrapper with a cookie jar:

```php
$session = $scraper->session();
$loginPage = $session->get('https://example.com/login');
$accountPage = $session->post('https://example.com/login', [
    'username' => $username,
    'password' => $password,
]);
$cookies = $session->cookies();
```

A session keeps cookies for requests made through that session object. It does not imply browser local storage, browser tabs, or persistent server-side login state.

## Response status and errors

Inspect `Page::response()` where response metadata is available. Handle network exceptions, unsafe URL exceptions, HTTP status errors, and response-size errors explicitly. Do not assume every URL returns HTML or a 2xx response.
