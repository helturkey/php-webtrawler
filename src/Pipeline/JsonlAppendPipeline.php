<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Pipeline;

use HElturkey\WebTrawler\Contracts\ItemPipeline;
use RuntimeException;

/**
 * Appends each item to a JSONL file as soon as it is produced. Combine with Spider::collectItems() = false
 * and a crawl state store for large, resumable crawls. Items written before an interruption are kept.
 */
final class JsonlAppendPipeline implements ItemPipeline
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path)
    {
    }

    public function process(array $item): array
    {
        if ($this->handle === null) {
            $handle = @fopen($this->path, 'ab');
            if ($handle === false) {
                throw new RuntimeException(sprintf('Unable to open item file: %s', $this->path));
            }
            $this->handle = $handle;
        }

        $line = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($this->handle, $line) === false) {
            throw new RuntimeException('Unable to append item.');
        }
        fflush($this->handle);

        return $item;
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}
