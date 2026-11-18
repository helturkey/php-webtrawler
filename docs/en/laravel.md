# Laravel integration

PHP WebTrawler integrates with Laravel 13 through package auto-discovery, a service provider, a `Scrape` facade, configuration, and an Artisan command. The package CI currently tests Laravel 13/Testbench compatibility pairs; run tests against the exact Laravel version used by your application.

## Install

```bash
composer require helturkey/php-webtrawler
php artisan vendor:publish --tag=scraper-config
```

The service provider is declared in Composer package metadata. Laravel should discover it automatically.

## Configuration

The published `config/scraper.php` includes:

| Key | Environment | Default |
|---|---|---|
| `transport` | `SCRAPER_TRANSPORT` | `guzzle` |
| `emulation` | `SCRAPER_EMULATION` | `null` |
| `timeout` | `SCRAPER_TIMEOUT` | `30` seconds |
| `connect_timeout` | `SCRAPER_CONNECT_TIMEOUT` | `10` seconds |
| `max_body_bytes` | `SCRAPER_MAX_BODY_BYTES` | `20 MiB` |
| `block_private_networks` | `SCRAPER_BLOCK_PRIVATE_NETWORKS` | `true` |
| `allowed_hosts` | `SCRAPER_ALLOWED_HOSTS` | empty list |
| `enforce_default_security` | `SCRAPER_ENFORCE_DEFAULT_SECURITY` | `true` |
| `retries.max_attempts` | `SCRAPER_RETRY_ATTEMPTS` | `3` |
| `retries.base_delay_ms` | — | `500` |
| `retries.max_delay_ms` | — | `30000` |
| `proxies` | `SCRAPER_PROXIES` | empty list |
| `snapshots.driver` | `SCRAPER_SNAPSHOTS` | `file` |
| `snapshots.path` | — | `storage/app/scraper/snapshots.json` |

`SCRAPER_ALLOWED_HOSTS` is a comma-separated host list. `SCRAPER_PROXIES` is a comma-separated proxy list; use a secret manager or environment injection and do not commit credentials.

Laravel's service provider enables enforced default security. Per-request options may narrow the configured policy but cannot disable private-network blocking, raise the body cap, expand the allow-list, turn off required TLS verification, or replace an enforced proxy route.

## Use the facade

```php
use HElturkey\WebTrawler\Laravel\Facades\Scrape;

$page = Scrape::get('https://docs.example.com/');
$title = $page->title();
$links = $page->css('a')->map(static fn ($link): array => [
    'text' => $link->text(),
    'href' => $link->attr('href'),
]);
```

The facade resolves the package scraper singleton. Use dependency injection or the facade according to your application's conventions.

## Artisan command

The package registers `scraper:fetch`. Use the installed command's help output to inspect supported arguments and options:

```bash
php artisan list scraper
php artisan help scraper:fetch
```

## Proxies and browser rendering

The configured proxy pool applies to HTTP fetches and spider requests. It is not automatically applied to Playwright. Browser traffic needs its own browser proxy option or mandatory network-level egress routing.

## Operational notes

- Keep `block_private_networks=true` when URLs can be influenced by users.
- Add an allow-list for known integration domains where possible.
- Store snapshots under Laravel's private storage path and apply retention/backup policy.
- Do not return raw scraped HTML directly in Blade without escaping or sanitization.
- Add application tests for service-provider configuration, facade use, authorization, rate limits, and persistence. Package integration tests do not replace tests in the consuming application.
