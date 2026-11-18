# Optional driver absence test fixture

This is a separate Composer root project that installs PHP WebTrawler as a path
repository dependency. Composer intentionally does not install a dependency's
`require-dev` packages, so `dekor/php-impity` and `playwright-php/playwright`
are absent here while PHPUnit remains available to run the package's optional
availability tests.

Run from this directory:

```bash
COMPOSER_ROOT_VERSION=1.0.0 composer update --no-interaction --prefer-dist
COMPOSER_ROOT_VERSION=1.0.0 composer validate --strict
rm -rf vendor
COMPOSER_ROOT_VERSION=1.0.0 composer install --no-interaction --prefer-dist
vendor/bin/phpunit --configuration=phpunit.xml
```

The workflow additionally asserts that neither optional package is present
in the generated lock file and that no tests are skipped.
