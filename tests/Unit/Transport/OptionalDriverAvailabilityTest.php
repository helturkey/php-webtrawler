<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Transport;

use HElturkey\WebTrawler\Transport\Drivers\ImpityTransport;
use PHPUnit\Framework\TestCase;

final class OptionalDriverAvailabilityTest extends TestCase
{
    public function test_impity_transport_fails_with_actionable_message_when_optional_package_is_missing(): void
    {
        if (class_exists(\Dekor\Impity\Client::class)) {
            self::markTestSkipped('php-impity is installed in this environment.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer require dekor/php-impity');
        (new ImpityTransport())->send('GET', 'https://example.test');
    }
    public function test_amp_transport_fails_with_actionable_message_when_optional_package_is_missing(): void
    {
        if (class_exists(\Amp\Http\Client\HttpClientBuilder::class)) {
            self::markTestSkipped('amphp/http-client is installed in this environment.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer require amphp/http-client');
        new \HElturkey\WebTrawler\Transport\Drivers\AmpTransport();
    }

    public function test_playwright_driver_fails_with_actionable_message_when_optional_package_is_missing(): void
    {
        if (class_exists(\Playwright\Playwright::class)) {
            self::markTestSkipped('Playwright PHP is installed in this environment.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer require playwright-php/playwright');
        (new \HElturkey\WebTrawler\Browser\PlaywrightBrowserDriver())->render('https://example.test');
    }

}
