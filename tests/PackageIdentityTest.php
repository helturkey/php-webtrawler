<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Version;
use PHPUnit\Framework\TestCase;

final class PackageIdentityTest extends TestCase
{
    public function test_the_package_name_and_psr4_namespace_are_configured(): void
    {
        $composer = json_decode(
            file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('helturkey/php-webtrawler', $composer['name']);
        self::assertSame(
            ['HElturkey\\WebTrawler\\' => 'src/'],
            $composer['autoload']['psr-4'],
        );
        self::assertSame(
            ['HElturkey\\WebTrawler\\Tests\\' => 'tests/'],
            $composer['autoload-dev']['psr-4'],
        );
    }

    public function test_the_new_public_namespace_is_autoloadable(): void
    {
        self::assertTrue(class_exists(Scraper::class));
        self::assertTrue(class_exists(Version::class));
        self::assertSame('1.0.0', Version::CURRENT);
        self::assertSame('HElturkey-WebTrawler: 1.0.0', Version::userAgent());
    }

    public function test_the_pre_release_namespace_is_not_kept_as_a_compatibility_alias(): void
    {
        self::assertFalse(class_exists('Helturkey\\Scraper\\Scraper'));
    }
}
