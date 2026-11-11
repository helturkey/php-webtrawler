<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Export;

final class JsonlExporter
{
    /**
     * Writes one JSON document per line ("\n" terminated on every platform).
     * Invalid UTF-8 in scraped values is replaced rather than aborting the export.
     *
     * @param iterable<array<string, mixed>> $items
     */
    public function export(iterable $items, string $filePath): int
    {
        return AtomicFile::write($filePath, static function ($stream) use ($items): int {
            $count = 0;
            foreach ($items as $item) {
                $line = json_encode(
                    $item,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
                );
                AtomicFile::writeAll($stream, $line . "\n");
                $count++;
            }

            return $count;
        });
    }
}
