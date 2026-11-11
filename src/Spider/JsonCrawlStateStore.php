<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Spider;

use JsonException;
use RuntimeException;

/**
 * Durable, atomically written checkpoint of a crawl (queue, de-duplication set, counters) so an
 * interrupted run can resume. The file is created with mode 0600 because queued requests can carry
 * headers and form data.
 */
final class JsonCrawlStateStore
{
    public const VERSION = 1;

    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** @return array<string, mixed>|null */
    public function load(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $json = @file_get_contents($this->path);
        if ($json === false) {
            throw new RuntimeException(sprintf('Unable to read crawl state: %s', $this->path));
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Crawl state %s is corrupt; delete it to start over.', $this->path), 0, $exception);
        }
        if (!is_array($data) || ($data['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException(sprintf('Crawl state %s has an unsupported version.', $this->path));
        }

        return $data;
    }

    /** @param array<string, mixed> $state */
    public function save(array $state): void
    {
        $state['version'] = self::VERSION;
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create crawl state directory: %s', $directory));
        }

        $temporary = $directory . DIRECTORY_SEPARATOR . '.' . basename($this->path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $previousUmask = umask(0077);
        try {
            $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json, LOCK_EX) === false || !@rename($temporary, $this->path)) {
                throw new RuntimeException(sprintf('Unable to write crawl state: %s', $this->path));
            }
        } finally {
            umask($previousUmask);
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
