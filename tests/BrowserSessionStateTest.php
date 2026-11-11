<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Browser\BrowserSessionState;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BrowserSessionStateTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/php-webtrawler-browser-state-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_dir($file)) {
                foreach (glob($file . '/*') ?: [] as $nested) {
                    @unlink($nested);
                }
                @rmdir($file);
            } else {
                @unlink($file);
            }
        }
        @rmdir($this->directory);
    }

    public function test_session_ids_resolve_to_non_reversible_paths_and_persist_state(): void
    {
        $state = BrowserSessionState::fromOptions([
            'session_id' => '../../customer@example.test/session',
            'session_directory' => $this->directory,
        ]);

        self::assertNotNull($state);
        self::assertNull($state->existingLoadPath());

        $state->persist(static function (string $temporaryPath): void {
            file_put_contents($temporaryPath, '{"cookies":[],"origins":[]}');
        });

        $expectedPath = $this->directory . '/' . hash('sha256', '../../customer@example.test/session') . '.json';
        self::assertSame($expectedPath, $state->existingLoadPath());
        self::assertSame('{"cookies":[],"origins":[]}', file_get_contents($expectedPath));
        self::assertSame(0600, fileperms($expectedPath) & 0777);
        self::assertSame([], glob($this->directory . '/*.tmp') ?: []);
    }

    public function test_explicit_storage_state_path_is_loaded_and_refreshed(): void
    {
        $path = $this->directory . '/state.json';
        mkdir($this->directory, 0700, true);
        file_put_contents($path, '{"old":true}');
        chmod($path, 0600);

        $state = BrowserSessionState::fromOptions(['storage_state' => $path]);

        self::assertNotNull($state);
        self::assertSame($path, $state->existingLoadPath());
        $state->persist(static function (string $temporaryPath): void {
            file_put_contents($temporaryPath, '{"new":true}');
        });
        self::assertSame('{"new":true}', file_get_contents($path));
    }

    public function test_save_only_path_is_supported_without_a_load_path(): void
    {
        $state = BrowserSessionState::fromOptions(['save_storage_state' => $this->directory . '/export.json']);

        self::assertNotNull($state);
        self::assertNull($state->existingLoadPath());
        $state->persist(static fn (string $path) => file_put_contents($path, '{}'));
        self::assertFileExists($this->directory . '/export.json');
    }

    public function test_it_rejects_conflicting_session_options(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BrowserSessionState::fromOptions([
            'session_id' => 'session-a',
            'storage_state' => $this->directory . '/state.json',
        ]);
    }
}
