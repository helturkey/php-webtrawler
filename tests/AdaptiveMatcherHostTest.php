<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Adaptive\ElementSnapshot;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\TestCase;

final class AdaptiveMatcherHostTest extends TestCase
{
    public function test_it_does_not_apply_a_snapshot_to_a_different_host(): void
    {
        $store = new InMemorySnapshotStore();
        $snapshot = new ElementSnapshot('h1', 'Title', [], [], null, [], [], 1, 'https://first.example.test', time());
        $store->put('title', $snapshot);
        $page = Page::fromHtml('<html><body><h1>Title</h1></body></html>', 'https://second.example.test', $store, new AdaptiveMatcher());

        $result = $page->adaptive('title')->first();

        self::assertFalse($result->accepted);
        self::assertSame('Saved snapshot belongs to a different host.', $result->reason);
    }


    public function test_it_does_not_apply_a_snapshot_to_a_different_non_default_port(): void
    {
        $store = new InMemorySnapshotStore();
        $snapshot = new ElementSnapshot('h1', 'Title', ['data-testid' => 'title'], [], null, [], [], 1, 'https://example.test:8443', time());
        $store->put('title', $snapshot);
        $page = Page::fromHtml('<html><body><h1 data-testid="title">Title</h1></body></html>', 'https://example.test', $store, new AdaptiveMatcher());

        $result = $page->adaptive('title')->first();

        self::assertFalse($result->accepted);
        self::assertSame('host_mismatch', $result->status);
    }
}
