<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Contracts\Http\TransportInterface;
use HElturkey\WebTrawler\Contracts\SnapshotStore;
use HElturkey\WebTrawler\Contracts\BrowserDriver;
use HElturkey\WebTrawler\Contracts\DownloaderMiddleware;
use HElturkey\WebTrawler\Contracts\ItemPipeline;
use HElturkey\WebTrawler\Contracts\CrawlDeduplicator;
use HElturkey\WebTrawler\Contracts\ResponseParser;
use HElturkey\WebTrawler\Fetching\BrowserFetcher;
use HElturkey\WebTrawler\Fetching\Downloader;
use HElturkey\WebTrawler\Policy\RequestPolicy;
use HElturkey\WebTrawler\Fetching\Fetcher;
use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Retry\RetryPolicy;
use HElturkey\WebTrawler\Transport\Drivers\GuzzleTransport;
use HElturkey\WebTrawler\Session\Session;
use HElturkey\WebTrawler\Parsing\HtmlPageParser;
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
    private ResponseParser $parser;

    public function __construct(
        ?TransportInterface $transport = null,
        ?SnapshotStore $snapshotStore = null,
        ?AdaptiveMatcher $adaptiveMatcher = null,
        ?FetchOptions $defaultOptions = null,
        bool $enforceDefaultSecurity = false,
        ?RetryPolicy $defaultRetryPolicy = null,
        ?ResponseParser $parser = null,
    ) {
        $this->snapshotStore = $snapshotStore ?? new InMemorySnapshotStore();
        $this->adaptiveMatcher = $adaptiveMatcher ?? new AdaptiveMatcher();
        $this->parser = $parser ?? new HtmlPageParser($this->snapshotStore, $this->adaptiveMatcher);
        $this->fetcher = new Fetcher(
            new Downloader($transport ?? new GuzzleTransport(), new RequestPolicy($defaultOptions, $enforceDefaultSecurity), $defaultRetryPolicy),
            $this->parser,
        );
    }

    public static function create(
        ?TransportInterface $transport = null,
        ?SnapshotStore $snapshotStore = null,
        ?AdaptiveMatcher $adaptiveMatcher = null,
        ?FetchOptions $defaultOptions = null,
        bool $enforceDefaultSecurity = false,
        ?RetryPolicy $defaultRetryPolicy = null,
        ?ResponseParser $parser = null,
    ): self {
        return new self($transport, $snapshotStore, $adaptiveMatcher, $defaultOptions, $enforceDefaultSecurity, $defaultRetryPolicy, $parser);
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
        ?CrawlDeduplicator $crawlDeduplicator = null,
    ): CrawlResult {
        return (new SpiderRunner($this->fetcher, $middlewares, $pipelines, crawlDeduplicator: $crawlDeduplicator))->run($spider, $state, $shouldStop);
    }

    /** @param array<string, mixed> $options */
    public function fetchRendered(string $url, BrowserDriver $driver, array $options = []): \HElturkey\WebTrawler\Parsing\Page
    {
        return (new BrowserFetcher($this->parser))->fetch(
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
