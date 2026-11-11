# Browser rendering

Browser rendering is optional. Ordinary HTTP fetching and parsing do not require Node.js, Playwright, or Chromium.

## Install the browser integration

Install the PHP Playwright package as an application dependency (or use the package's documented development installation), install a supported Node.js runtime (20+), and install Chromium through the Playwright installer:

```bash
composer require playwright-php/playwright:^1.6
vendor/bin/playwright-install --with-deps chromium
```

The project browser CI image uses PHP 8.4, Node 24, npm, and Chromium. Browser/server versions should be installed and tested together; do not download a random system Chromium and assume compatibility.

## Render a page

```php
use HElturkey\WebTrawler\Browser\PlaywrightBrowserDriver;
use HElturkey\WebTrawler\Http\FetchOptions;
use HElturkey\WebTrawler\Scraper;

$options = new FetchOptions(
    timeout: 30,
    blockPrivateNetworks: true,
    allowedHosts: ['quotes.example.com'],
);

$scraper = Scraper::create(
    defaultOptions: $options,
    enforceDefaultSecurity: true,
);

$browser = new PlaywrightBrowserDriver();
$page = $scraper->fetchRendered('https://quotes.example.com/', $browser, [
    'wait_until' => 'domcontentloaded',
    'wait_for_selector' => '.quote',
]);

$quotes = $page->css('.quote')->map(static fn ($quote): array => [
    'text' => $quote->css('.text')->first()?->text(),
    'author' => $quote->css('.author')->first()?->text(),
]);
```

The driver starts a browser for the render operation. Do not assume a long-lived browser process or persistent tabs.

## Browser options

The driver accepts an options array. Important keys include:

| Key | Default/behaviour |
|---|---|
| `headless` | `true` |
| `args` | Additional Chromium arguments; review carefully because flags can weaken isolation |
| `slow_mo` | Optional delay between browser actions |
| `context` | Playwright browser-context options |
| `verify_tls` | Inherits scraper policy; keep true |
| `proxy` | Browser-specific proxy configuration |
| `headers` | Extra navigation/context headers |
| `max_network_requests` | Defaults to 500; clamped to 1–10,000 |
| `capture_request_bodies` | `false` by default |
| request body limits | Defaults to 256 KiB per request and 1 MiB total; configured values are capped |
| response capture | XHR/Fetch capture can be selected with `network_capture_patterns`; defaults and hard caps are enforced by the driver |
| navigation timeout | Inherits the scraper timeout and converts seconds to Playwright milliseconds |
| `wait_until` | `load` by default; use a lighter lifecycle such as `domcontentloaded` where appropriate |
| `wait_for_selector` | Optional CSS selector to wait for |
| `selector_timeout` | 10,000 ms default |
| `delay_ms` | Optional post-navigation delay, capped at 30 seconds |

Request/response capture is bounded by configured per-item and aggregate storage limits. These are capture limits, not a hard guarantee that Chromium never allocates a larger buffer internally. Avoid enabling body capture unless required.

## Browser sessions

The driver can persist cookies and local storage between browser runs through session state. It does not preserve JavaScript heap state, session storage, open tabs, active page objects, or an already-running browser.

Protect browser state as credentials. The default state directory must be private (`0700`) and owned by the current user; state files are created with restrictive permissions (`0600`). Ownership checks use `ext-posix`. An explicit shared-directory opt-out exists for controlled deployments; avoid it when sessions contain user credentials. Do not include state files in source control or container build contexts.

## Browser SSRF: important limitation

Playwright routing is not a complete SSRF boundary:

- Playwright's route handling does not provide a reliable per-hop gate for every redirect destination; Chromium may follow a redirect before the application can reject the resulting render.
- Service Workers can bypass routing; the driver blocks them when the routing guard is active, but this should not be treated as the only defence.
- The connected server address observed for the main navigation is not a complete record of the actual connected IP for every subresource.
- DNS rebinding and browser-internal network behaviour require controls below the application layer.

For untrusted URLs, run Chromium in an isolated container/VM/network namespace or behind a mandatory egress proxy/firewall. Deny direct outbound access to loopback, private, link-local, multicast, reserved, and cloud metadata ranges. Prevent the browser from bypassing that control. The project's isolated-browser CI uses Docker `--network none` and is a regression test for that environment; it does not mean that a developer's ordinary local browser process is isolated.

## Browser operational guidance

- Use `allowed_hosts` and `block_private_networks` as additional application-level checks.
- Do not pass arbitrary Chromium flags supplied by users.
- Limit browser concurrency at the worker/process level.
- Keep the browser image patched and rebuild it regularly.
- Do not return raw request/response bodies to untrusted callers without filtering and size controls.
- Treat the rendered DOM and captured API payloads as untrusted remote content.
