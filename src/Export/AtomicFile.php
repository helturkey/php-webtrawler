<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Export;

use RuntimeException;
use Throwable;

/**
 * Writes through a temporary file in the target directory and renames it into
 * place on success, so a failed export never leaves a truncated file behind
 * and never destroys a previous good export.
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
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf('Exporter output directory does not exist: %s', $directory));
        }

        $temporaryPath = $directory . DIRECTORY_SEPARATOR . '.' . basename($filePath) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $stream = @fopen($temporaryPath, 'wb');
        if ($stream === false) {
            throw new RuntimeException(sprintf('Unable to open exporter output: %s', $filePath));
        }

        try {
            $count = $writer($stream);
            if (!fflush($stream)) {
                throw new RuntimeException('Unable to flush exporter output.');
            }
            fclose($stream);
            $stream = null;
            if (!@rename($temporaryPath, $filePath)) {
                throw new RuntimeException(sprintf('Unable to move export into place: %s', $filePath));
            }

            return $count;
        } catch (Throwable $exception) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($temporaryPath);
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
