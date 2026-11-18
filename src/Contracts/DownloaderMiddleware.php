<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Contracts;

use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use Throwable;

interface DownloaderMiddleware
{
    public function beforeFetch(SpiderRequest $request): SpiderRequest;

    public function afterFetch(SpiderRequest $request, Page $page): Page;

    public function onError(SpiderRequest $request, Throwable $exception): void;
}
