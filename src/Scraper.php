<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Contracts\HttpTransport;
use HElturkey\WebTrawler\Contracts\SnapshotStore;
use HElturkey\WebTrawler\Contracts\BrowserDriver;
use HElturkey\WebTrawler\Contracts\DownloaderMiddleware;
use HElturkey\WebTrawler\Contracts\ItemPipeline;
use HElturkey\WebTrawler\Http\BrowserFetcher;
use HElturkey\WebTrawler\Http\Fetcher;
use HElturkey\WebTrawler\Http\FetchOptions;
use HElturkey\WebTrawler\Http\RetryPolicy;
use HElturkey\WebTrawler\Http\GuzzleTransport;
use HElturkey\WebTrawler\Http\Session;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Spider\CrawlResult;
use HElturkey\WebTrawler\Spider\JsonCrawlStateStore;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderRunner;

final readonly class Scraper
{
    private Fetcher $fetcher;
    private SnapshotStore $snapshotStore;
    private AdaptiveMatcher $adaptiveMatcher;

    public function __construct(
        ?HttpTransport $transport = null,
        ?SnapshotStore $snapshotStore = null,
        ?AdaptiveMatcher $adaptiveMatcher = null,
        ?FetchOptions $defaultOptions = null,
        bool $enforceDefaultSecurity = false,
        ?RetryPolicy $defaultRetryPolicy = null,
    ) {
        $this->snapshotStore = $snapshotStore ?? new InMemorySnapshotStore();
        $this->adaptiveMatcher = $adaptiveMatcher ?? new AdaptiveMatcher();
        $this->fetcher = new Fetcher(
            $transport ?? new GuzzleTransport(),
            $this->snapshotStore,
            $this->adaptiveMatcher,
            $defaultOptions,
            $enforceDefaultSecurity,
            $defaultRetryPolicy,
        );
    }

    public static function create(
        ?HttpTransport $transport = null,
        ?SnapshotStore $snapshotStore = null,
        ?AdaptiveMatcher $adaptiveMatcher = null,
        ?FetchOptions $defaultOptions = null,
        bool $enforceDefaultSecurity = false,
        ?RetryPolicy $defaultRetryPolicy = null,
    ): self {
        return new self($transport, $snapshotStore, $adaptiveMatcher, $defaultOptions, $enforceDefaultSecurity, $defaultRetryPolicy);
    }

    public function fetch(string $url, ?FetchOptions $options = null): \HElturkey\WebTrawler\Parsing\Page
    {
        return $this->fetcher->fetch($url, $options);
    }

    public function get(string $url, ?FetchOptions $options = null): \HElturkey\WebTrawler\Parsing\Page
    {
        return $this->fetcher->get($url, $options);
    }

    /** @param array<string, mixed> $data */
    public function post(string $url, array $data = [], ?FetchOptions $options = null): \HElturkey\WebTrawler\Parsing\Page
    {
        return $this->fetcher->post($url, $data, $options);
    }

    public function session(): Session
    {
        return new Session($this->fetcher);
    }

    /**
     * @param list<DownloaderMiddleware> $middlewares
     * @param list<ItemPipeline> $pipelines
     */
    public function crawl(
        Spider $spider,
        array $middlewares = [],
        array $pipelines = [],
        ?JsonCrawlStateStore $state = null,
        ?callable $shouldStop = null,
    ): CrawlResult {
        return (new SpiderRunner($this->fetcher, $middlewares, $pipelines))->run($spider, $state, $shouldStop);
    }

    /** @param array<string, mixed> $options */
    public function fetchRendered(string $url, BrowserDriver $driver, array $options = []): \HElturkey\WebTrawler\Parsing\Page
    {
        return (new BrowserFetcher($this->snapshotStore, $this->adaptiveMatcher))->fetch(
            $url,
            $driver,
            $this->fetcher->browserOptions($options),
        );
    }

    public function snapshotStore(): SnapshotStore
    {
        return $this->snapshotStore;
    }
}
