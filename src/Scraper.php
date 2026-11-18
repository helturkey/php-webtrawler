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
    private RequestPolicy $requestPolicy;
    private ?RetryPolicy $retryPolicy;

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
        $this->requestPolicy = new RequestPolicy($defaultOptions, $enforceDefaultSecurity);
        $this->retryPolicy = $defaultRetryPolicy;
        $this->fetcher = new Fetcher(
            new Downloader($transport ?? new GuzzleTransport(), $this->requestPolicy, $defaultRetryPolicy),
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
     * @param array<string, TransportInterface> $sessions Extra named transports a spider can route requests to with
     *        SpiderRequest::viaSession(), for example `['browser' => new BrowserTransport($driver)]`. They share this
     *        scraper's security policy and parser, and are used one request at a time.
     */
    public function crawl(
        Spider $spider,
        array $middlewares = [],
        array $pipelines = [],
        ?JsonCrawlStateStore $state = null,
        ?callable $shouldStop = null,
        ?CrawlDeduplicator $crawlDeduplicator = null,
        array $sessions = [],
    ): CrawlResult {
        return $this->runner($middlewares, $pipelines, $crawlDeduplicator, $sessions)->run($spider, $state, $shouldStop);
    }

    /**
     * Crawl and hand items to the caller as soon as they are produced instead of collecting them.
     *
     * ```php
     * foreach ($scraper->stream($spider) as $item) { save($item); }
     * ```
     *
     * Items still pass through $pipelines first. The generator's return value is the final CrawlResult
     * (`$generator->getReturn()`), and its items list is empty because nothing is buffered. Breaking out of the
     * loop stops the crawl and runs the same cleanup as an interrupted crawl. The crawl runs inside a PHP Fiber;
     * do not suspend that fiber from your own pipelines.
     *
     * @param list<DownloaderMiddleware> $middlewares
     * @param list<ItemPipeline> $pipelines
     * @param array<string, TransportInterface> $sessions
     * @return \Generator<int, array<string, mixed>, mixed, CrawlResult>
     */
    public function stream(
        Spider $spider,
        array $middlewares = [],
        array $pipelines = [],
        ?JsonCrawlStateStore $state = null,
        ?callable $shouldStop = null,
        ?CrawlDeduplicator $crawlDeduplicator = null,
        array $sessions = [],
    ): \Generator {
        $emitter = new class () implements ItemPipeline {
            public function process(array $item): array
            {
                \Fiber::suspend($item);

                return $item;
            }
        };
        $spider = (clone $spider)->configure(['collectItems' => false]);
        $runner = $this->runner($middlewares, [...$pipelines, $emitter], $crawlDeduplicator, $sessions);
        $fiber = new \Fiber(static fn (): CrawlResult => $runner->run($spider, $state, $shouldStop));

        $item = $fiber->start();
        while (!$fiber->isTerminated()) {
            yield $item;
            $item = $fiber->resume();
        }

        return $fiber->getReturn();
    }

    /**
     * @param list<DownloaderMiddleware> $middlewares
     * @param list<ItemPipeline> $pipelines
     * @param array<string, TransportInterface> $sessions
     */
    private function runner(array $middlewares, array $pipelines, ?CrawlDeduplicator $crawlDeduplicator, array $sessions): SpiderRunner
    {
        $fetchers = [];
        foreach ($sessions as $name => $transport) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $name) !== 1 || !$transport instanceof TransportInterface) {
                throw new \InvalidArgumentException('Sessions must map a lowercase session name to a TransportInterface.');
            }
            $fetchers[$name] = new Fetcher(new Downloader($transport, $this->requestPolicy, $this->retryPolicy), $this->parser);
        }

        return new SpiderRunner($this->fetcher, $middlewares, $pipelines, crawlDeduplicator: $crawlDeduplicator, sessions: $fetchers);
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
