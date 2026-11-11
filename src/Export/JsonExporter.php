<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Export;

/** Streams items into a single JSON array (use JsonlExporter for append-friendly output). */
final class JsonExporter
{
    /** @param iterable<array<string, mixed>> $items */
    public function export(iterable $items, string $filePath, bool $pretty = false): int
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | ($pretty ? JSON_PRETTY_PRINT : 0);

        return AtomicFile::write($filePath, static function ($stream) use ($items, $flags, $pretty): int {
            AtomicFile::writeAll($stream, '[');
            $count = 0;
            foreach ($items as $item) {
                AtomicFile::writeAll($stream, ($count > 0 ? ',' : '') . ($pretty ? "\n" : '') . json_encode($item, $flags));
                $count++;
            }
            AtomicFile::writeAll($stream, ($pretty && $count > 0 ? "\n" : '') . "]\n");

            return $count;
        });
    }
}
