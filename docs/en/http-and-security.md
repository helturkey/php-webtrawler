# HTTP options and security

This page describes the library's controls and their limits. It is not a substitute for application threat modelling or network isolation.

## Security policy defaults

For applications that accept user-supplied URLs, create an explicit policy and enforce it:

```php
$policy = new FetchOptions(
    timeout: 20,
    connectTimeout: 5,
    verifyTls: true,
    allowRedirects: true,
    maxRedirects: 5,
    maxBodyBytes: 10 * 1024 * 1024,
    blockPrivateNetworks: true,
    allowedHosts: ['catalog.example.com'],
);

$scraper = Scraper::create(
    defaultOptions: $policy,
    enforceDefaultSecurity: true,
);
```

With `enforceDefaultSecurity: true`, request-specific options are tightened against the configured policy. They cannot weaken private-network blocking, increase the response cap, disable required TLS verification, re-enable disabled redirects, replace an enforced proxy, or expand an enforced host allow-list. Conflicting non-empty allow-lists are intersected; an empty intersection is rejected.

Without enforced defaults, each request's options are used as supplied, subject to the transport's own checks. Do not expose unrestricted `FetchOptions` construction to untrusted callers.

## SSRF controls

The HTTP URL guard checks URL syntax and DNS resolution, rejects unresolved hosts, and can reject loopback/private/link-local addresses. The supplied Guzzle, Rnet, and Impity adapters validate redirect destinations before following supported redirect hops and perform final destination checks.

Important limitations:

1. DNS can change between validation and the eventual connection (DNS rebinding / time-of-check-to-time-of-use). An application-level DNS check cannot guarantee the connected socket destination on every platform and transport.
2. Custom transports may not implement the same redirect boundary. Do not assume a third-party transport inherits every guarantee merely because it implements `HttpTransport`.
3. A host allow-list is a routing restriction, not a content trust boundary. Allowed sites can still serve malicious HTML, redirects, files, or data.
4. Browser rendering has additional limitations described in [Browser rendering](browser.md).

For high-risk workloads, run fetchers behind outbound firewall rules or an egress proxy that denies loopback, private, link-local, multicast, reserved, and cloud metadata ranges. Ensure the application cannot bypass that proxy.

## Redirects and origins

Redirect checks must apply to every hop, not just the first URL. Cross-origin redirects should not forward origin-bound credentials such as `Authorization` or cookies. Origins include scheme, hostname, and port; a scheme or port change is a different origin even when the hostname is unchanged.

Prefer explicit allow-lists for known domains. Avoid wildcarding broad parent domains where subdomains may be delegated or user-controlled.

## TLS and proxies

Keep `verifyTls=true`. Disabling certificate verification exposes requests to interception and invalidates important transport security assumptions.

Proxy credentials are secrets. Supply them through environment variables or a secret manager; never commit them to source code, fixtures, or logs. Proxy URLs may contain credentials in userinfo, path, query, or provider-specific tokens. The library masks proxy URLs in its own statistics and errors, but application-level logging must still be reviewed.

`ProxyPool` supports round-robin, random, and least-failures selection, per-proxy failure counts, cooldown, and a single half-open probe after cooldown. If all proxies are cooling down, acquisition fails unless a `ProxyTransport` is explicitly configured for direct fallback. Direct fallback is a network-policy decision; leave it disabled when proxy use is mandatory.

## Response limits and encodings

The default HTTP response body limit is 20 MiB. Set a smaller application-specific limit for untrusted sites. Passing `null` disables this limit and is not recommended for arbitrary URLs. Limits protect the HTTP body capture path; they do not automatically constrain every browser subresource or all memory allocated by a browser engine.

Use the explicit `encoding` option only when the source charset is known. Non-UTF-8 request bodies and persisted state require special care; resumable crawl state rejects unsupported or unsafe values rather than silently changing the request.

## Logging and data retention

- Do not log raw authentication headers, cookies, proxy credentials, response bodies, or session-state files.
- Use `Page::url()` carefully; an effective URL may include sensitive query values supplied by the remote site. Sanitize before logging.
- Crawl errors and built-in URL diagnostics are redacted, but custom middleware and pipelines are application code and must redact their own data.
- Set retention limits for snapshots, crawl state, exported data, and browser storage state.
- Store secrets outside repository files and container build contexts.

## Security testing

Run the security-focused tests in `composer test`, the native transport tests, and the isolated Chromium integration. For browser SSRF, rely on real network isolation in addition to request-routing tests. See [Testing and release](testing-and-release.md).
