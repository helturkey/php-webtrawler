<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Export;

use RuntimeException;
use Throwable;

/**
 * Writes to an exclusively-created temporary file beside the destination and
 * renames it into place only after the complete output has been flushed.
 *
 * Temporary and final files are owner-readable/writable only (0600) by default.
 *
 * @internal
 */
final class AtomicFile
{
    /**
     * @param callable(resource): int $writer Receives the open stream and returns the number of rows written.
     */
    public static function write(string $filePath, callable $writer): int
    {
        if ($filePath === '' || str_contains($filePath, "\0")) {
            throw new RuntimeException('Exporter output path is invalid.');
        }

        $directory = dirname($filePath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException(sprintf('Exporter output directory is missing or not writable: %s', $directory));
        }
        if (is_dir($filePath)) {
            throw new RuntimeException(sprintf('Exporter output path is a directory: %s', $filePath));
        }

        $temporaryPath = null;
        $stream = false;

        // x+b fails if the path already exists, including when it is a symlink.
        // A fresh 128-bit name makes collisions practically impossible; the
        // exclusive mode ensures a collision can never redirect our writes.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = $directory . DIRECTORY_SEPARATOR . '.webtrawler-export-' . bin2hex(random_bytes(16)) . '.tmp';
            // Tighten the umask while creating the file so it is never group/world readable, not even briefly.
            $previousUmask = umask(0077);
            try {
                $stream = @fopen($candidate, 'x+b');
            } finally {
                umask($previousUmask);
            }
            if (is_resource($stream)) {
                $temporaryPath = $candidate;
                break;
            }
        }

        if (!is_resource($stream) || $temporaryPath === null) {
            throw new RuntimeException(sprintf('Unable to create a secure temporary export beside: %s', $filePath));
        }

        try {
            if (!@chmod($temporaryPath, 0600)) {
                throw new RuntimeException('Unable to restrict permissions on temporary export file.');
            }

            $count = $writer($stream);
            if (!fflush($stream)) {
                throw new RuntimeException('Unable to flush exporter output.');
            }
            if (function_exists('fsync') && !fsync($stream)) {
                throw new RuntimeException('Unable to synchronize exporter output.');
            }

            $closed = fclose($stream);
            $stream = false;
            if (!$closed) {
                throw new RuntimeException('Unable to close exporter output.');
            }

            if (!@rename($temporaryPath, $filePath)) {
                throw new RuntimeException(sprintf('Unable to move export into place: %s', $filePath));
            }

            return $count;
        } catch (Throwable $exception) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($temporaryPath !== null) {
                @unlink($temporaryPath);
            }

            throw $exception;
        }
    }

    /** @param resource $stream */
    public static function writeAll($stream, string $payload): void
    {
        $length = strlen($payload);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($stream, substr($payload, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write export output completely.');
            }
            $offset += $written;
        }
    }
}
