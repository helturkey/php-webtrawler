<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Spider;

use HElturkey\WebTrawler\Http\AutoThrottle;
use HElturkey\WebTrawler\Http\RetryPolicy;
use HElturkey\WebTrawler\Parsing\Page;

abstract class Spider
{
    /** @var list<string> */
    protected array $startUrls = [];

    /** @var list<string> Empty means all hosts are allowed. */
    protected array $allowedHosts = [];

    protected int $maxPages = 1000;
    protected int $maxDepth = 10;

    /** Minimum pause between request starts to the same host, in milliseconds (politeness delay). */
    protected int $requestDelayMs = 0;

    /** Maximum simultaneous downloads. Values above 1 need an async-capable transport (GuzzleTransport). */
    protected int $concurrency = 1;

    /** Keep items in CrawlResult::$items. Turn off for large crawls and write items from an ItemPipeline. */
    protected bool $collectItems = true;

    /** @return iterable<SpiderRequest> */
    public function startRequests(): iterable
    {
        foreach ($this->startUrls as $url) {
            yield SpiderRequest::get($url);
        }
    }

    /** @return iterable<SpiderRequest|SpiderItem|array<string, mixed>> */
    abstract public function parse(Page $page, SpiderRequest $request): iterable;

    /** @return list<string> */
    public function allowedHosts(): array
    {
        return $this->allowedHosts;
    }

    public function maxPages(): int
    {
        return $this->maxPages;
    }

    public function maxDepth(): int
    {
        return $this->maxDepth;
    }

    public function requestDelayMs(): int
    {
        return $this->requestDelayMs;
    }

    public function concurrency(): int
    {
        return $this->concurrency;
    }

    public function collectItems(): bool
    {
        return $this->collectItems;
    }

    protected ?RetryPolicy $retryPolicy = null;

    protected ?AutoThrottle $autoThrottle = null;

    /** Retries for transient failures and retryable statuses (429/5xx ...). null disables retries. */
    public function retryPolicy(): ?RetryPolicy
    {
        return $this->retryPolicy;
    }

    /** Adaptive per-host delay. null disables it. */
    public function autoThrottle(): ?AutoThrottle
    {
        return $this->autoThrottle;
    }

    /**
     * Override crawl settings at run time (used by the CLI). Unknown keys are rejected.
     *
     * @param array{maxPages?: int, maxDepth?: int, concurrency?: int, requestDelayMs?: int, collectItems?: bool, retryPolicy?: RetryPolicy|null, autoThrottle?: AutoThrottle|null} $settings
     */
    public function configure(array $settings): static
    {
        foreach ($settings as $name => $value) {
            if (!in_array($name, ['maxPages', 'maxDepth', 'concurrency', 'requestDelayMs', 'collectItems', 'retryPolicy', 'autoThrottle'], true)) {
                throw new \InvalidArgumentException('Unknown spider setting: ' . $name);
            }
            $this->{$name} = $value;
        }

        return $this;
    }

    /**
     * Whether a response with this status should be parsed. Rejected responses are
     * reported in CrawlResult::$errors instead. Override to parse e.g. custom 404 pages.
     */
    public function acceptsStatus(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }
}
