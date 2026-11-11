# Getting started

## Requirements

- PHP `^8.4`.
- Composer 2.
- Required PHP extensions: `ext-dom`, `ext-json`, `ext-libxml`, and `ext-xmlwriter`.
- Outbound network access to the target site for ordinary HTTP fetching.

Optional capabilities have extra requirements:

| Capability | Additional requirement |
|---|---|
| Internationalized host names (IDN) | `ext-intl` |
| Broader/faster non-UTF-8 conversion | `ext-mbstring`; `iconv` is used where available as a fallback |
| Graceful CLI stop and checkpoint on signals | `ext-pcntl` |
| Browser-session directory ownership checks | `ext-posix` |
| Chromium rendering | `playwright-php/playwright`, Node.js 20+, and installed Playwright Chromium |
| Impity transport | `dekor/php-impity` and a matching `curl-impersonate` executable |
| Rnet transport | The compatible php-rnet native extension |
| Laravel integration | Laravel 13 application; Testbench is used by the package integration tests |

## Install

```bash
composer require helturkey/php-webtrawler
```

The Composer package installs the core HTTP, parsing, spider, adaptive matching, CLI, export, and MCP code. Optional packages are not part of the core runtime dependency graph; install only the integration you use.

## First request

```php
<?php

declare(strict_types=1);

use HElturkey\WebTrawler\Http\FetchOptions;
use HElturkey\WebTrawler\Scraper;

require __DIR__ . '/vendor/autoload.php';

$options = new FetchOptions(
    timeout: 20.0,
    connectTimeout: 5.0,
    blockPrivateNetworks: true,
    maxBodyBytes: 8 * 1024 * 1024,
);

$scraper = Scraper::create(
    defaultOptions: $options,
    enforceDefaultSecurity: true,
);

$page = $scraper->get('https://example.com/');
echo $page->title() ?? 'Untitled';
```

The example enables private-network blocking explicitly. The plain `Scraper::create()` defaults are intended for general library use and do not automatically block private targets; choose and enforce a policy that matches your application.

## Parse a page

```php
$firstHeading = $page->css('h1')->first();
$title = $firstHeading?->text();
$links = $page->css('a')->map(static fn ($link): array => [
    'text' => $link->text(),
    'href' => $link->attr('href'),
]);
```

`Page::css()` returns an `ElementCollection`. `first()` returns an `Element|null`; collections also provide `all()`, `map()`, `each()`, `count()`, and `isEmpty()`.

## Use a POST request

```php
use HElturkey\WebTrawler\Http\FetchOptions;

$page = $scraper->post(
    'https://example.com/search',
    ['q' => 'scraping'],
    new FetchOptions(headers: ['Accept' => 'text/html']),
);
```

POST form data is encoded through the transport request options. Do not put passwords, API keys, or personal data in URLs.

## Next steps

- Review [HTTP options and security](http-and-security.md) before accepting arbitrary URLs.
- Learn about [sessions and parsing](fetching-and-parsing.md).
- Build a [spider](spiders.md) for multiple pages.
- Add [adaptive selectors](adaptive-selectors.md) when markup changes between runs.
