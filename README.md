# WebTrawler PHP Scraper

**PHP Scraper** is a PHP-first web scraping toolkit for HTTP fetching, DOM parsing, adaptive element matching, concurrent crawling, optional Chromium rendering, structured exports, a CLI, an MCP server, and Laravel integration.

## Documentation

Choose your language:

- **English:** [Full documentation — `docs/en`](docs/en/README.md)
- **العربية:** [التوثيق الكامل — `docs/ar`](docs/ar/README.md)
- **Français / Español / Türkçe:** [`docs/fr`](docs/fr/README.md) · [`docs/es`](docs/es/README.md) · [`docs/tr`](docs/tr/README.md) (quick starts)
- [Documentation language index](docs/README.md)

The documentation covers installation, fetching and parsing, HTTP security, adaptive selectors, spiders and resumability, transports and proxies, Playwright, CLI/MCP, Laravel, exporters, Docker deployment and CI, testing, troubleshooting, and API entry points.

## Install

```bash
composer require helturkey/php-webtrawler
```

The core requires PHP 8.4+ and `ext-dom`, `ext-json`, `ext-libxml`, and `ext-xmlwriter`. Playwright, php-rnet, and Impity are optional integrations with additional requirements. See [English requirements](docs/en/getting-started.md#requirements) or [المتطلبات بالعربية](docs/ar/getting-started.md#المتطلبات).

## Minimal example

```php
<?php

declare(strict_types=1);

use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Scraper;

require __DIR__ . '/vendor/autoload.php';

$options = new FetchOptions(
    blockPrivateNetworks: true,
    allowedHosts: ['example.com'],
    maxBodyBytes: 10 * 1024 * 1024,
);

$scraper = Scraper::create(
    defaultOptions: $options,
    enforceDefaultSecurity: true,
);

$page = $scraper->get('https://example.com/');
echo $page->title() ?? 'Untitled';
```

## Security note

For untrusted URLs, configure `blockPrivateNetworks`, an appropriate `allowedHosts` list, finite timeouts and a finite response-size limit, and set `enforceDefaultSecurity: true`. HTTP-level URL checks do not fully prevent DNS rebinding. Playwright routing is best-effort and is not a complete SSRF boundary; use network-level egress isolation for untrusted browser targets. See [HTTP security](docs/en/http-and-security.md) and [browser security](docs/en/browser.md).

## Docker

The production runtime and unified AMD64 CI image are built from one Dockerfile. Run the complete Docker validation with:

```bash
composer test-docker
```

See the [Docker deployment and CI guide](docs/en/docker.md) or [الدليل العربي](docs/ar/docker.md).

## Development checks

```bash
composer validate --strict
composer cs
composer stan
composer test
composer check
```

Optional integration commands and the full release checklist are documented in [Testing and release](docs/en/testing-and-release.md) and [الاختبارات والإصدار](docs/ar/testing-and-release.md).

## Project links

- [Issues](https://github.com/helturkey/php-webtrawler/issues)
- [Source](https://github.com/helturkey/php-webtrawler)
