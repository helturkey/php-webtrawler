<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Spider;

use GuzzleHttp\Promise\PromiseInterface;
use HElturkey\WebTrawler\Parsing\Page;
use SplQueue;
use Throwable;

/** @internal Mutable state of one SpiderRunner::run() call. */
final class CrawlContext
{
    /** @var SplQueue<QueuedRequest> */
    public SplQueue $ready;

    /** @var list<array{0: int, 1: QueuedRequest}> Retries waiting for their back-off: [notBeforeMs, request]. */
    public array $delayed = [];

    /** @var array<int, array{item: QueuedRequest, request: SpiderRequest, promise: PromiseInterface}> */
    public array $inflight = [];

    /** @var list<array{item: QueuedRequest, request: SpiderRequest, page: Page|null, error: Throwable|null}> Finished downloads awaiting processing. */
    public array $done = [];

    /** @var array<string, true> */
    public array $seen = [];

    /** @var array<string, int> */
    public array $lastStartMs = [];

    public int $nextId = 1;
    public int $scheduled = 0;
    public int $attempted = 0;
    public int $completed = 0;
    public int $retried = 0;
    public int $itemsCount = 0;
    public int $sinceCheckpoint = 0;

    /** @var list<array<string, mixed>> */
    public array $items = [];

    /** @var list<string> */
    public array $visited = [];

    /** @var list<array{url: string, message: string, exception: string}> */
    public array $errors = [];

    public function __construct()
    {
        $this->ready = new SplQueue();
    }
}
