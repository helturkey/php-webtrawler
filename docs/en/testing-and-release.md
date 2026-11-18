# Testing, CI and release

## Local quality checks

The project targets PHP 8.4 as its minimum and primary release runtime. Run checks under PHP 8.4; CI also tests PHP 8.5 for forward compatibility.

```bash
composer validate --strict
composer cs
composer stan
composer test
composer check
```

`composer check` runs PHP-CS-Fixer, PHPStan, and the standard PHPUnit suite. It does not by itself run every optional native/browser/live integration.

Tests are grouped by layer: `tests/Unit/{Adaptive,Browser,Concurrency,Export,Fetching,Frontend,Package,Parsing,Policy,Proxy,Retry,Spider,Storage,Support,Transport}` and `tests/Integration/{Live,Local,Optional}`. Run the groups separately when debugging:

```bash
composer test-unit
composer test-integration
```

## Integration tests

### Laravel

```bash
composer test-laravel
```

Runs the package Laravel integration suite under Testbench. Also test the package inside the consuming application's actual Laravel version and configuration.

### Impity

Install a matching curl-impersonate executable and set:

```bash
export IMPITY_CURL_IMPERSONATE_PATH=/absolute/path/to/curl-impersonate
composer test-impity
```

A skipped test means the optional integration did not run. Release CI treats skipped tests in the dedicated Impity job as failure.

### php-rnet

Build/install the extension for the exact PHP ABI and architecture, then run:

```bash
php -d extension=/absolute/path/to/librnet.so vendor/bin/phpunit \
  --filter test_the_php_rnet_adapter_against_the_real_extension \
  tests/Integration/Local/LocalServerIntegrationTest.php
```

The release CI builds the pinned php-rnet version against PHP 8.4 Linux x86_64. A library built for a different PHP minor ABI or architecture cannot be assumed loadable.

### Chromium in an isolated network

Install Playwright and Chromium, then:

```bash
SCRAPER_RUN_BROWSER_INTEGRATION=1 composer test-browser
```

The browser-isolation test is meaningful only when the process really runs in a network-isolated container/VM. The CI Docker image runs with `--network none` and passes `SCRAPER_BROWSER_EGRESS_ISOLATED=1` only at container runtime. Do not set this variable on an ordinary networked local process.

### Live service tests

The opt-in live suite uses the same public targets and selectors as the package examples: `https://quotes.toscrape.com/` for quotes and `https://books.toscrape.com/` for books. It exercises the real fetch stack (`Downloader`, `Fetcher`, `Scraper`, `Session`, parser and page API), installed HTTP transports, browser adapters, spider/pipeline, SQLite deduplication when `pdo_sqlite` is available, adaptive matching, and the exporters. Optional transports and browser tests are skipped when their runtime prerequisites are absent. Proxy routing additionally needs `SCRAPER_TEST_PROXY`.

These tests are intentionally excluded from ordinary `composer test` because they require external network access and depend on third-party sites remaining available. Run them explicitly:

```bash
SCRAPER_RUN_LIVE_TESTS=1 composer test-live
```

There is currently no GitHub Actions job for the public live targets. These tests are opt-in and run only when explicitly requested. A failure may reflect remote site changes, rate limiting, or external availability rather than a local regression; investigate before retrying.

### Missing optional drivers

`tests/Unit/Transport/OptionalDriverAvailabilityTest.php` verifies that missing Amp, Impity, and Playwright packages produce actionable installation errors. These tests run as part of the ordinary PHPUnit suite; they skip the relevant case when that optional package is installed.

## Unified Docker validation

Run the Docker-specific production and integration checks with one command:

```bash
composer test-docker
```

The command builds the production runtime and runs its smoke checks as a dependency of one unified `ci` image. It then runs that image once, with external networking disabled. The image executes the local Playwright/Chromium suite, the Impity suite using curl-impersonate, and the php-rnet native-extension suite sequentially. Required browser/native tests may not be skipped.

The complete Docker target is `linux/amd64` because the php-rnet extension is built and tested for that architecture. Apple Silicon hosts need AMD64 emulation enabled in their Docker VM. The CI image contains compilers and development dependencies and must not be deployed; only the `runtime` target is intended for production.

This Docker command covers the production smoke checks and the dedicated browser/native transport integration suites. It does not replace `composer check`, the ordinary PHPUnit suite, Laravel integration tests, or opt-in live-site tests.

See [Docker deployment and CI](docker.md) for prerequisites, image usage, test coverage, caching, and troubleshooting.

## CI matrix and gates

The checked-in GitHub Actions workflow currently runs:

- the quality suite on PHP 8.4 and PHP 8.5, including Composer validation, PHP-CS-Fixer, PHPStan, PHPUnit, and Laravel integration tests;
- a separate Chromium integration job that runs the local browser fixture in a Docker container with external networking disabled.

The php-rnet and Impity transport Docker setup is available under `docker/`, but it is not currently wired into `.github/workflows/ci.yml`. Public live-target tests are also not part of GitHub Actions; run them explicitly with `composer test-live` as described above.
