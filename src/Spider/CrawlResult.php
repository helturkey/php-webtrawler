<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Spider;

final readonly class CrawlResult
{
    /**
     * @param list<array<string, mixed>> $items Empty when Spider::collectItems() is false.
     * @param list<string> $visitedUrls
     * @param list<array{url: string, message: string, exception: string}> $errors
     * @param int $requestsScheduled Unique requests accepted into the queue (including a resumed run's earlier ones).
     * @param int $requestsCompleted Pages fetched, accepted and parsed in total (including a resumed run's earlier ones).
     * @param int $requestsRetried Extra attempts made after retryable failures during this run.
     * @param bool $interrupted True when the run stopped early (shouldStop) with work still pending.
     * @param bool $resumed True when the run continued from saved state.
     * @param int $itemsCount Items produced by this run, even when they were not collected.
     */
    public function __construct(
        public array $items,
        public array $visitedUrls,
        public array $errors,
        public int $requestsScheduled,
        public int $requestsCompleted,
        public int $requestsRetried = 0,
        public bool $interrupted = false,
        public bool $resumed = false,
        public int $itemsCount = 0,
    ) {
    }
}
