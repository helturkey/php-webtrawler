# PHP WebTrawler

A PHP-first scraping toolkit for HTTP fetching, DOM parsing, adaptive element matching, concurrent crawling, browser rendering, structured exports, and optional Laravel/MCP integrations.

> **Release target:** · **Runtime baseline:** PHP 8.4 · **License:** MIT

PHP WebTrawler keeps ordinary HTTP scraping independent from Chromium. Guzzle is the default async-capable transport. Rnet and Impity are optional transports with separate native/runtime prerequisites. Browser rendering is an optional Playwright integration.

## Documentation

- [Getting started](getting-started.md)
- [Fetching, sessions and parsing](fetching-and-parsing.md)
- [HTTP options and security](http-and-security.md)
- [Adaptive selectors](adaptive-selectors.md)
- [Spiders and resumable crawling](spiders.md)
- [Transports, retries and proxies](transports-and-proxies.md)
- [Browser rendering](browser.md)
- [CLI and MCP server](cli-and-mcp.md)
- [Laravel integration](laravel.md)
- [Exports and extension points](exporting-and-extending.md)
- [Testing, CI and release](testing-and-release.md)
- [API reference](api-reference.md)

## Quick example

```php
<?php

declare(strict_types=1);

use HElturkey\WebTrawler\Http\FetchOptions;
use HElturkey\WebTrawler\Scraper;

require __DIR__ . '/vendor/autoload.php';

$options = new FetchOptions(
    timeout: 20,
    maxBodyBytes: 10 * 1024 * 1024,
    blockPrivateNetworks: true,
    allowedHosts: ['example.com'],
);

$scraper = Scraper::create(
    defaultOptions: $options,
    enforceDefaultSecurity: true,
);

$page = $scraper->get('https://example.com/');

printf("Title: %s\n", $page->title() ?? '(no title)');
foreach ($page->css('h1')->all() as $heading) {
    echo $heading->text() . PHP_EOL;
}
```

## Security baseline

- Treat scraped HTML, text, links, attributes, and API payloads as untrusted input.
- If URLs are user-controlled, enable private-network blocking and enforce a host allow-list appropriate to the application.
- Keep TLS verification enabled. Do not disable redirect checks or response-size limits to make a failing site work without understanding the consequence.
- HTTP URL guards are application-level controls, not a substitute for network egress restrictions against DNS rebinding or compromised DNS.
- Playwright routing is defence in depth, not a complete SSRF boundary. Isolate browser traffic at the container/VM/firewall/egress-proxy layer for untrusted targets.
- Never log raw URLs, proxy URLs, cookies, authorization headers, or session-state contents. The library redacts many URL and proxy values in its own diagnostics, but applications must protect their own logs and pipelines too.

Read [HTTP options and security](http-and-security.md) and [Browser rendering](browser.md) before exposing scraping to untrusted users.

## Installation

```bash
composer require helturkey/php-webtrawler
```

The core HTTP and parsing features require PHP 8.4+ and the required extensions listed in [Getting started](getting-started.md). Optional drivers are not required for ordinary Guzzle-based scraping.

## Quality checks

```bash
composer validate --strict
composer cs
composer stan
composer test
composer check
```

See [Testing, CI and release](testing-and-release.md) for optional transport, Laravel, Chromium, live-site, and release checks.

## Links

- [Arabic documentation](../ar/README.md)
- [English documentation](README.md)
- [Issue tracker](https://github.com/helturkey/php-webtrawler/issues)
- [Source repository](https://github.com/helturkey/php-webtrawler)
