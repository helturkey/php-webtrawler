<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Browser;

use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves Playwright storage-state paths and persists them with restrictive
 * permissions and an atomic rename.
 *
 * Playwright storage state contains cookies and localStorage. It does not
 * persist sessionStorage, open tabs, or in-memory JavaScript state.
 */
final readonly class BrowserSessionState
{
    public function __construct(
        private ?string $loadPath,
        private ?string $savePath,
        private bool $requireOwnedDirectory = false,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromOptions(array $options): ?self
    {
        $storagePath = self::optionalPath($options, 'storage_state');
        $savePath = self::optionalPath($options, 'save_storage_state');
        $allowSharedDirectory = $options['allow_shared_session_directory'] ?? false;
        if (!is_bool($allowSharedDirectory)) {
            throw new InvalidArgumentException('The allow_shared_session_directory option must be a boolean.');
        }
        $sessionId = $options['session_id'] ?? null;

        if ($sessionId !== null && (!is_string($sessionId) || trim($sessionId) === '')) {
            throw new InvalidArgumentException('The browser session_id option must be a non-empty string.');
        }

        if (is_string($sessionId)) {
            if ($storagePath !== null || $savePath !== null) {
                throw new InvalidArgumentException('Use either session_id or storage_state/save_storage_state, not both.');
            }

            $directory = $options['session_directory'] ?? null;
            if ($directory !== null && (!is_string($directory) || trim($directory) === '')) {
                throw new InvalidArgumentException('The session_directory option must be a non-empty path.');
            }

            // Storage state holds cookies and tokens: the directory must be private (0700, ours) whether it
            // is the per-user default or a custom path. 'allow_shared_session_directory' => true opts out.
            $enforceDirectory = !$allowSharedDirectory;
            $directory = is_string($directory)
                ? $directory
                : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'helturkey-php-webtrawler-' . self::userKey() . DIRECTORY_SEPARATOR . 'sessions';

            // Hash identifiers so arbitrary user input cannot escape the state directory.
            $path = rtrim($directory, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . hash('sha256', $sessionId)
                . '.json';

            return new self($path, $path, $enforceDirectory);
        }

        if ($storagePath === null && $savePath === null) {
            return null;
        }

        // Explicit paths can contain the same cookies and bearer tokens as generated session files.
        // Protect their directory and file unless the caller opts out deliberately.
        $enforceDirectory = !$allowSharedDirectory;

        return new self($storagePath, $savePath ?? $storagePath, $enforceDirectory);
    }

    public function existingLoadPath(): ?string
    {
        if ($this->loadPath === null || !is_file($this->loadPath)) {
            return null;
        }

        if ($this->requireOwnedDirectory) {
            self::assertOwnedDirectory(dirname($this->loadPath));
            self::assertOwnedFile($this->loadPath);
        }

        return $this->loadPath;
    }

    /** @param callable(string): void $writeState */
    public function persist(callable $writeState): void
    {
        if ($this->savePath === null) {
            return;
        }

        $directory = dirname($this->savePath);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create browser session directory: %s', $directory));
        }

        if ($this->requireOwnedDirectory) {
            self::assertOwnedDirectory($directory);
        }

        $temporaryPath = $directory . DIRECTORY_SEPARATOR
            . '.' . basename($this->savePath) . '.' . bin2hex(random_bytes(8)) . '.tmp';

        // Create the file ourselves with 0600 *before* the driver writes to it. The driver may run in a
        // separate (Node) process whose umask we cannot change, but it writes into the existing file, so
        // the restrictive mode is in place for the whole lifetime of the secret content.
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($temporaryPath, 'xb');
            if ($handle === false) {
                throw new RuntimeException('Unable to create the temporary browser session file.');
            }
            fclose($handle);
            @chmod($temporaryPath, 0600);

            $writeState($temporaryPath);

            if (!is_file($temporaryPath)) {
                throw new RuntimeException('The browser driver did not create the requested storage-state file.');
            }

            // If the driver replaced the file instead of writing into it, tighten the mode now.
            @chmod($temporaryPath, 0600);
            // PHP caches stat results (including across chmod), so read the mode fresh.
            clearstatcache(true, $temporaryPath);
            $mode = @fileperms($temporaryPath);
            if ($this->requireOwnedDirectory) {
                self::assertOwnedFile($temporaryPath);
            } elseif ($mode !== false && DIRECTORY_SEPARATOR === '/' && ($mode & 0077) !== 0) {
                throw new RuntimeException('Browser session file is accessible to other users; refusing to keep it.');
            }

            if (!@rename($temporaryPath, $this->savePath)) {
                throw new RuntimeException(sprintf('Unable to atomically replace browser session state: %s', $this->savePath));
            }
        } finally {
            umask($previousUmask);
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private static function userKey(): string
    {
        return function_exists('posix_geteuid') ? (string) posix_geteuid() : hash('crc32b', (string) get_current_user());
    }

    /** Refuse a state directory that another local user could have created or can write to. */
    private static function assertOwnedDirectory(string $directory): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        if (!function_exists('posix_geteuid')) {
            throw new RuntimeException('Secure browser session paths require ext-posix for ownership checks (or explicitly set "allow_shared_session_directory" => true).');
        }
        if (!is_dir($directory) || is_link($directory)) {
            throw new RuntimeException(sprintf('Browser session directory %s must exist and must not be a symbolic link.', $directory));
        }

        // The per-user parent in the shared temp dir matters as much as the leaf:
        // whoever owns it could swap the leaf directory out from under us.
        $parent = dirname($directory);
        if (str_starts_with(basename($parent), 'helturkey-php-webtrawler-') && $parent !== $directory) {
            self::assertOwnedDirectory($parent);
        }

        clearstatcache(true, $directory);
        $owner = @fileowner($directory);
        $permissions = @fileperms($directory);
        if ($owner !== posix_geteuid() || $permissions === false || ($permissions & 0777) !== 0700) {
            throw new RuntimeException(sprintf(
                'Browser session directory %s must be owned by the current user with mode 0700 (set "allow_shared_session_directory" => true to opt out).',
                $directory,
            ));
        }
    }

    private static function assertOwnedFile(string $path): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        if (!function_exists('posix_geteuid')) {
            throw new RuntimeException('Secure browser session files require ext-posix for ownership checks (or explicitly set "allow_shared_session_directory" => true).');
        }
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Browser session state must be a regular file, not a symbolic link.');
        }

        clearstatcache(true, $path);
        $owner = @fileowner($path);
        $permissions = @fileperms($path);
        if ($owner !== posix_geteuid() || $permissions === false || ($permissions & 0777) !== 0600) {
            throw new RuntimeException('Browser session state must be owned by the current user with mode 0600.');
        }
    }

    /** @param array<string, mixed> $options */
    private static function optionalPath(array $options, string $key): ?string
    {
        $path = $options[$key] ?? null;
        if ($path === null) {
            return null;
        }

        if (!is_string($path) || trim($path) === '') {
            throw new InvalidArgumentException(sprintf('The %s option must be a non-empty path.', $key));
        }

        return $path;
    }
}
