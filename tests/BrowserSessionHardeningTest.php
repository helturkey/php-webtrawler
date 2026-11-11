<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Browser\BrowserSessionState;
use PHPUnit\Framework\TestCase;

final class BrowserSessionHardeningTest extends TestCase
{
    public function test_state_files_are_private_and_default_directory_is_per_user(): void
    {
        if (!function_exists('posix_geteuid')) {
            self::markTestSkipped('POSIX permissions are required.');
        }

        $directory = sys_get_temp_dir() . '/scraper-session-' . bin2hex(random_bytes(6));
        $state = BrowserSessionState::fromOptions(['session_id' => 'abc', 'session_directory' => $directory]);
        self::assertNotNull($state);

        $state->persist(static function (string $path): void {
            file_put_contents($path, '{"cookies":[]}');
            chmod($path, 0644); // simulate a driver that ignores umask
        });

        $files = glob($directory . '/*.json') ?: [];
        self::assertCount(1, $files);
        self::assertSame(0600, fileperms($files[0]) & 0777);

        unlink($files[0]);
        rmdir($directory);
    }

    public function test_explicit_storage_state_files_must_be_owned_and_private(): void
    {
        if (!function_exists('posix_geteuid')) {
            self::markTestSkipped('POSIX permissions are required.');
        }

        $directory = sys_get_temp_dir() . '/scraper-explicit-session-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        chmod($directory, 0700);
        $path = $directory . '/account.json';
        file_put_contents($path, '{"cookies":[]}');
        chmod($path, 0644);

        try {
            $state = BrowserSessionState::fromOptions(['storage_state' => $path]);
            self::assertNotNull($state);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('mode 0600');
            $state->existingLoadPath();
        } finally {
            unlink($path);
            rmdir($directory);
        }
    }

    public function test_explicit_session_file_must_be_owner_read_write_mode_0600(): void
    {
        if (!function_exists('posix_geteuid')) {
            self::markTestSkipped('POSIX permissions are required.');
        }

        $directory = sys_get_temp_dir() . '/scraper-session-file-mode-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $path = $directory . '/state.json';
        file_put_contents($path, '{}');
        chmod($path, 0400);

        try {
            $state = BrowserSessionState::fromOptions(['storage_state' => $path]);
            self::assertNotNull($state);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('mode 0600');
            $state->existingLoadPath();
        } finally {
            chmod($path, 0600);
            unlink($path);
            rmdir($directory);
        }
    }

    public function test_custom_session_directory_must_have_exact_private_mode_0700(): void
    {
        if (!function_exists('posix_geteuid')) {
            self::markTestSkipped('POSIX permissions are required.');
        }

        $directory = sys_get_temp_dir() . '/scraper-session-mode-' . bin2hex(random_bytes(6));
        mkdir($directory, 0750);
        chmod($directory, 0750);
        $state = BrowserSessionState::fromOptions(['session_id' => 'abc', 'session_directory' => $directory]);

        try {
            self::assertNotNull($state);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('mode 0700');
            $state->persist(static function (string $path): void {
                file_put_contents($path, '{}');
            });
        } finally {
            chmod($directory, 0700);
            rmdir($directory);
        }
    }

    public function test_allow_shared_session_directory_must_be_boolean(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BrowserSessionState::fromOptions(['save_storage_state' => '/tmp/state.json', 'allow_shared_session_directory' => 'true']);
    }

    public function test_an_insecure_default_session_directory_is_refused(): void
    {
        if (!function_exists('posix_geteuid')) {
            self::markTestSkipped('POSIX permissions are required.');
        }

        $parent = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'helturkey-php-webtrawler-' . posix_geteuid();
        $created = !is_dir($parent);
        if (!$created) {
            self::markTestSkipped('The per-user session directory already exists on this machine.');
        }

        mkdir($parent . '/sessions', 0777, true);
        chmod($parent, 0777);
        chmod($parent . '/sessions', 0777);

        try {
            $state = BrowserSessionState::fromOptions(['session_id' => 'abc']);
            self::assertNotNull($state);
            $this->expectException(\RuntimeException::class);
            $state->persist(static function (string $path): void {
                file_put_contents($path, '{}');
            });
        } finally {
            rmdir($parent . '/sessions');
            rmdir($parent);
        }
    }
}
