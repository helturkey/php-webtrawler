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
  tests/LocalServerIntegrationTest.php
```

The release CI builds the pinned php-rnet version against PHP 8.4 Linux x86_64. A library built for a different PHP minor ABI or architecture cannot be assumed loadable.

### Chromium in an isolated network

Install Playwright and Chromium, then:

```bash
SCRAPER_RUN_BROWSER_INTEGRATION=1 composer test-browser
```

The browser-isolation test is meaningful only when the process really runs in a network-isolated container/VM. The CI Docker image runs with `--network none` and passes `SCRAPER_BROWSER_EGRESS_ISOLATED=1` only at container runtime. Do not set this variable on an ordinary networked local process.

### Live browser tests

The two public-site smoke tests are intentionally excluded from ordinary `composer test` because they require external network access and depend on third-party sites remaining available. Run them explicitly:

```bash
SCRAPER_RUN_LIVE_TESTS=1 composer test-live
```

The live CI job is manual (`workflow_dispatch`) and opt-in. A failure may reflect remote site changes, rate limiting, or external availability rather than a local regression; investigate before retrying.

### Optional packages absent

The CI consumer fixture installs PHP WebTrawler as a path dependency without installing its `require-dev` dependencies. It verifies that Impity and Playwright classes are absent and that the package reports actionable missing-driver errors. Keep optional packages in `require-dev` only when they are used for project development/tests; do not move them into core `require` unless the core runtime truly depends on them.

## CI matrix and gates

The workflow includes:

- PHP 8.4 highest dependencies;
- PHP 8.4 lowest supported dependencies;
- PHP 8.5 highest dependencies;
- Laravel compatibility pairs under PHP 8.4;
- php-rnet native extension build/test;
- Impity + curl-impersonate integration;
- isolated Chromium integration;
- optional-driver-absent consumer installation;
- opt-in live-browser tests.