<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Spider;

/** @internal A request waiting in (or retried through) the spider queue. */
final class QueuedRequest
{
    public function __construct(
        public readonly SpiderRequest $request,
        public readonly int $attempt = 1,
        /** True once the request has been counted against maxPages (retries never count twice). */
        public bool $counted = false,
    ) {
    }
}
