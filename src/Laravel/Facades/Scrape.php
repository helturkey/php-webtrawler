<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Laravel\Facades;

use HElturkey\WebTrawler\Scraper;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \HElturkey\WebTrawler\Parsing\Page get(string $url, ?\HElturkey\WebTrawler\Http\FetchOptions $options = null)
 * @method static \HElturkey\WebTrawler\Parsing\Page post(string $url, array<string, mixed> $data = [], ?\HElturkey\WebTrawler\Http\FetchOptions $options = null)
 * @method static \HElturkey\WebTrawler\Spider\CrawlResult crawl(\HElturkey\WebTrawler\Spider\Spider $spider, list<\HElturkey\WebTrawler\Contracts\DownloaderMiddleware> $middlewares = [], list<\HElturkey\WebTrawler\Contracts\ItemPipeline> $pipelines = [], ?\HElturkey\WebTrawler\Spider\JsonCrawlStateStore $state = null, ?callable $shouldStop = null)
 *
 * @see Scraper
 */
final class Scrape extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Scraper::class;
    }
}
