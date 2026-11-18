<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Storage;

use HElturkey\WebTrawler\Adaptive\ElementSnapshot;
use HElturkey\WebTrawler\Storage\SQLiteSnapshotStore;
use PHPUnit\Framework\TestCase;

final class SQLiteSnapshotStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
        $this->directory = sys_get_temp_dir() . '/php-webtrawler-sqlite-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    private function snapshot(string $text = 'Book title'): ElementSnapshot
    {
        return new ElementSnapshot(
            tag: 'h2',
            text: $text,
            attributes: ['data-testid' => 'book-title'],
            classes: ['heading'],
            parent: ['tag' => 'section', 'id' => '', 'classes' => ['details']],
            ancestors: [],
            siblings: [],
            sameTagPosition: 1,
            sourceUrl: 'https://example.test',
            createdAt: 123,
        );
    }

    public function test_it_persists_across_instances_and_removes_snapshots(): void
    {
        $path = $this->directory . '/nested/snapshots.sqlite';
        (new SQLiteSnapshotStore($path))->put('book-title', $this->snapshot());

        $reloaded = new SQLiteSnapshotStore($path);
        self::assertSame('Book title', $reloaded->get('book-title')?->text);
        self::assertSame(['book-title'], array_keys($reloaded->all()));

        $reloaded->remove('book-title');
        self::assertNull($reloaded->get('book-title'));
        self::assertSame([], $reloaded->all());
    }

    public function test_putting_an_existing_key_replaces_it(): void
    {
        $store = new SQLiteSnapshotStore($this->directory . '/s.sqlite');
        $store->put('k', $this->snapshot('old'));
        $store->put('k', $this->snapshot('new'));

        self::assertSame('new', $store->get('k')?->text);
        self::assertCount(1, $store->all());
    }

    public function test_unicode_text_round_trips_unchanged(): void
    {
        $store = new SQLiteSnapshotStore($this->directory . '/s.sqlite');
        $store->put('عنوان', $this->snapshot('كتاب 📚 « test »'));

        self::assertSame('كتاب 📚 « test »', $store->get('عنوان')?->text);
    }

    public function test_missing_keys_return_null_and_removing_them_is_harmless(): void
    {
        $store = new SQLiteSnapshotStore($this->directory . '/s.sqlite');

        self::assertNull($store->get('missing'));
        $store->remove('missing');
        self::assertSame([], $store->all());
    }

    public function test_invalid_keys_are_rejected_like_the_other_stores(): void
    {
        $store = new SQLiteSnapshotStore($this->directory . '/s.sqlite');

        $this->expectException(\InvalidArgumentException::class);
        $store->put(str_repeat('k', 191), $this->snapshot());
    }

    public function test_an_in_memory_database_works_for_a_single_process(): void
    {
        $store = new SQLiteSnapshotStore(':memory:');
        $store->put('a', $this->snapshot());

        self::assertSame('Book title', $store->get('a')?->text);
    }

    public function test_the_database_uses_wal_mode_so_readers_do_not_block_the_writer(): void
    {
        $path = $this->directory . '/wal.sqlite';
        (new SQLiteSnapshotStore($path))->put('k', $this->snapshot());

        $pdo = new \PDO('sqlite:' . $path);
        self::assertSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')?->fetchColumn()));
    }
}
