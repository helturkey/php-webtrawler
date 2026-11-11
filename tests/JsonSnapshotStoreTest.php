<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Adaptive\ElementSnapshot;
use HElturkey\WebTrawler\Storage\JsonSnapshotStore;
use PHPUnit\Framework\TestCase;

final class JsonSnapshotStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/php-webtrawler-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function test_it_persists_and_removes_snapshots(): void
    {
        $path = $this->directory . '/snapshots.json';
        $store = new JsonSnapshotStore($path);
        $snapshot = new ElementSnapshot(
            tag: 'h2',
            text: 'Book title',
            attributes: ['data-testid' => 'book-title'],
            classes: ['heading'],
            parent: ['tag' => 'section', 'id' => '', 'classes' => ['details']],
            ancestors: [],
            siblings: [],
            sameTagPosition: 1,
            sourceUrl: 'https://example.test',
            createdAt: 123,
        );

        $store->put('book-title', $snapshot);
        $reloaded = new JsonSnapshotStore($path);

        self::assertSame('Book title', $reloaded->get('book-title')?->text);
        self::assertArrayHasKey('book-title', $reloaded->all());

        $reloaded->remove('book-title');
        self::assertNull($store->get('book-title'));
    }
}
