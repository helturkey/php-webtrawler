<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Spider;

use Closure;
use GuzzleHttp\Psr7\Uri;
use HElturkey\WebTrawler\Concurrency\Pending;
use HElturkey\WebTrawler\Contracts\DownloaderMiddleware;
use HElturkey\WebTrawler\Contracts\ItemPipeline;
use HElturkey\WebTrawler\Contracts\CrawlDeduplicator;
use HElturkey\WebTrawler\Exception\HttpStatusException;
use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Response\FetchResponse;
use HElturkey\WebTrawler\Fetching\Fetcher;
use HElturkey\WebTrawler\Policy\HostPolicy;
use HElturkey\WebTrawler\Policy\UrlNormalizer;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Support\UrlSanitizer;
use RuntimeException;
use Throwable;

/**
 * Runs a spider with bounded parallel downloads, non-blocking retries, per-host throttling and
 * optional checkpointing.
 *
 * Downloads overlap (Spider::concurrency()) and are handled in the order they finish: whichever response
 * completes first is parsed first, and a free slot is refilled before the slowest request returns. Parsing,
 * Spider::parse(), middlewares and pipelines run one at a time on the calling thread, so they never run
 * concurrently. With concurrency above 1 the order of results follows completion order, not discovery order.
 */
final readonly class SpiderRunner
{
    /** @var Closure(int): void */
    private Closure $sleeper;

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param list<DownloaderMiddleware> $middlewares
     * @param list<ItemPipeline> $pipelines
     * @param (callable(int): void)|null $sleeper Receives milliseconds; defaults to usleep().
     * @param (callable(): int)|null $clock Monotonic milliseconds; injectable for tests.
     */
    public function __construct(
        private Fetcher $fetcher,
        private array $middlewares = [],
        private array $pipelines = [],
        ?callable $sleeper = null,
        ?callable $clock = null,
        private ?CrawlDeduplicator $crawlDeduplicator = null,
    ) {
        $this->sleeper = $sleeper !== null
            ? Closure::fromCallable($sleeper)
            : static function (int $milliseconds): void {
                usleep($milliseconds * 1000);
            };
        $this->clock = $clock !== null ? Closure::fromCallable($clock) : static fn (): int => intdiv(hrtime(true), 1_000_000);
    }

    /**
     * @param (callable(): bool)|null $shouldStop Polled between requests; return true to stop cleanly (e.g. on SIGINT).
     * @param int $checkpointEvery Save $state after this many processed responses.
     */
    public function run(Spider $spider, ?JsonCrawlStateStore $state = null, ?callable $shouldStop = null, int $checkpointEvery = 20): CrawlResult
    {
        if ($spider->maxPages() < 1 || $spider->maxDepth() < 0 || $spider->requestDelayMs() < 0 || $spider->concurrency() < 1 || $checkpointEvery < 1) {
            throw new \InvalidArgumentException('Spider maxPages and concurrency must be positive; maxDepth and requestDelayMs cannot be negative.');
        }

        $async = $spider->concurrency() > 1;
        if ($async && !$this->fetcher->supportsAsync()) {
            throw new \InvalidArgumentException('Spider concurrency above 1 requires an async-capable transport. The configured transport is blocking; use an AsyncTransportInterface implementation such as AmpTransport or a managed GuzzleTransport.');
        }

        $ctx = new CrawlContext();
        $resumed = $state !== null && $this->restore($ctx, $spider, $state);
        if (!$resumed) {
            // Read at most maxPages seeds, counting every value the generator yields (duplicates included), and
            // never advance the generator once the limit is reached: foreach would fetch one value too many, and
            // an endless generator of duplicates would never stop.
            $seeds = $spider->startRequests();
            $iterator = match (true) {
                $seeds instanceof \Iterator => $seeds,
                is_array($seeds) => new \ArrayIterator($seeds),
                default => new \IteratorIterator($seeds),
            };
            $seedsRead = 0;
            $iterator->rewind();
            while ($iterator->valid()) {
                $request = $iterator->current();
                if (!$request instanceof SpiderRequest) {
                    throw new \UnexpectedValueException('Spider::startRequests() must yield SpiderRequest instances.');
                }
                $this->enqueue($ctx, $request, $spider->maxPages());
                if (++$seedsRead >= $spider->maxPages()) {
                    break;
                }
                $iterator->next();
            }
        }

        $interrupted = false;
        $incomplete = false;
        try {
            while (true) {
                if ($shouldStop !== null && $shouldStop()) {
                    $interrupted = true;
                    break;
                }

                $this->promoteDueRetries($ctx);

                while (count($ctx->inflight) < $spider->concurrency() && !$ctx->ready->isEmpty()) {
                    $item = $ctx->ready->dequeue();
                    if (!$item->counted && $ctx->attempted >= $spider->maxPages()) {
                        continue;
                    }
                    $this->dispatch($ctx, $spider, $item, $async);
                    if (!$async) {
                        break;
                    }
                }

                if ($async && $ctx->inflight !== []) {
                    if ($ctx->done === []) {
                        $this->fetcher->advance($this->tickTimeout($ctx, $shouldStop !== null));
                    }
                    $this->drain($ctx, $spider);
                } elseif ($ctx->inflight === [] && $ctx->ready->isEmpty()) {
                    if ($ctx->delayed === []) {
                        break;
                    }
                    ($this->sleeper)(max(0, $this->earliestRetryMs($ctx) - ($this->clock)()));
                }

                if ($state !== null && $ctx->sinceCheckpoint >= $checkpointEvery) {
                    $this->save($ctx, $spider, $state);
                }
            }
        } finally {
            // Decided before cancelling: cancellation settles the in-flight requests and empties that list.
            $incomplete = !$ctx->ready->isEmpty() || $ctx->delayed !== [] || $ctx->inflight !== [];
            if ($state !== null) {
                if ($incomplete) {
                    $this->save($ctx, $spider, $state);
                } else {
                    $state->clear();
                }
            }
            // Requests abandoned by an interrupted or failed crawl must not keep running in the background.
            if ($this->crawlDeduplicator !== null && ($state === null || !$incomplete)) {
                try {
                    $this->crawlDeduplicator->clearScope($ctx->dedupeScope);
                } catch (Throwable $storageException) {
                    $this->recordError($ctx, '', new RuntimeException(
                        'Unable to clear completed crawl deduplication scope: ' . $storageException->getMessage(),
                        previous: $storageException,
                    ));
                }
            }
            if ($async && $ctx->inflight !== []) {
                $this->fetcher->downloader()->cancelAll();
            }
        }

        return new CrawlResult(
            $ctx->items,
            $ctx->visited,
            $ctx->errors,
            $ctx->scheduled,
            $ctx->completed,
            $ctx->retried,
            $interrupted && $incomplete,
            $resumed,
            $ctx->itemsCount,
            $ctx->duplicateContentResponses,
        );
    }

    private function dispatch(CrawlContext $ctx, Spider $spider, QueuedRequest $item, bool $async): void
    {
        if (!$item->counted) {
            $ctx->attempted++;
            $item->counted = true;
        }

        $request = $item->request;
        try {
            $url = $this->canonicalUrl($request->url);
        } catch (Throwable $exception) {
            $this->recordError($ctx, $request->url, $exception);

            return;
        }
        if (!$this->hostAllowed($url, $spider->allowedHosts())) {
            $ctx->errors[] = ['url' => UrlSanitizer::sanitize($url), 'message' => 'Host is not in the spider allow-list.', 'exception' => 'HostNotAllowed'];

            return;
        }

        $request = $request->withUrl($url);
        try {
            foreach ($this->middlewares as $middleware) {
                $request = $middleware->beforeFetch($request);
            }
            $requestUrl = $this->canonicalUrl($request->url);
            if (!$this->hostAllowed($requestUrl, $spider->allowedHosts())) {
                throw new RuntimeException('Downloader middleware changed the request to a disallowed host.');
            }
            $request = $request->withUrl($requestUrl);

            $this->awaitSlot($ctx, $spider, (string) parse_url($request->url, PHP_URL_HOST));
            $options = $this->effectiveOptions($spider, $request);

            if (!$async) {
                // The spider runner owns retry scheduling for both sync and async transports. Do not
                // also apply the downloader's blocking one-shot retry wrapper here.
                $response = $this->fetcher->downloader()->download($request->url, $options, $request->method, applyDefaultRetries: false);
                $this->handle($ctx, $spider, $item, $request, $options, $response, null);

                return;
            }

            // The in-flight entry is registered before the listener is attached, so a download that
            // already settled (or settles inside tick()) is always moved from inflight to done exactly once.
            $id = $ctx->nextId++;
            $pending = $this->fetcher->downloader()->downloadAsync($request->url, $options, $request->method);
            $ctx->inflight[$id] = ['item' => $item, 'request' => $request, 'pending' => $pending];
            $pending->onSettled(static function (Pending $settled) use ($ctx, $id, $item, $request, $options): void {
                unset($ctx->inflight[$id]);
                $response = null;
                $error = $settled->error();
                if ($error === null) {
                    $value = $settled->value();
                    if ($value instanceof FetchResponse) {
                        $response = $value;
                    } else {
                        $error = new RuntimeException('The transport resolved a request without a FetchResponse.');
                    }
                }
                $ctx->done[] = ['item' => $item, 'request' => $request, 'options' => $options, 'response' => $response, 'error' => $error];
            });
        } catch (Throwable $exception) {
            $this->handle($ctx, $spider, $item, $request, null, null, $exception);
        }
    }

    /**
     * How long the transport may block while waiting for a download: no longer than the next retry is due,
     * and short enough that a stop request is noticed when the caller supplied one.
     */
    private function tickTimeout(CrawlContext $ctx, bool $pollsStop): ?float
    {
        $timeout = $pollsStop ? 1.0 : null;
        if ($ctx->delayed !== []) {
            $untilRetry = max(0, $this->earliestRetryMs($ctx) - ($this->clock)()) / 1000;
            $timeout = $timeout === null ? $untilRetry : min($timeout, $untilRetry);
        }

        return $timeout;
    }

    private function drain(CrawlContext $ctx, Spider $spider): void
    {
        while ($ctx->done !== []) {
            $entry = array_shift($ctx->done);
            $this->handle($ctx, $spider, $entry['item'], $entry['request'], $entry['options'], $entry['response'], $entry['error']);
        }
    }

    private function handle(CrawlContext $ctx, Spider $spider, QueuedRequest $item, SpiderRequest $request, ?FetchOptions $options, ?FetchResponse $response, ?Throwable $error): void
    {
        $ctx->sinceCheckpoint++;

        if ($error !== null) {
            if (!$this->scheduleRetry($ctx, $spider, $item, $error, null)) {
                $this->fail($ctx, $request, $error);
            }

            return;
        }
        if ($response === null || $options === null) {
            return;
        }

        $status = $response->status;
        $throttle = $spider->autoThrottle();
        if ($throttle !== null) {
            $throttle->record((string) parse_url($response->effectiveUrl, PHP_URL_HOST), $response->durationMs, $status);
        }
        if ($this->scheduleRetry($ctx, $spider, $item, null, $status, $response->header('Retry-After'))) {
            return;
        }

        try {
            if ($this->crawlDeduplicator !== null) {
                try {
                    $contentUrl = $this->canonicalUrl($response->effectiveUrl);
                    if ($this->crawlDeduplicator->recordContent($ctx->dedupeScope, $contentUrl, $response->contentHash(), strlen($response->body))) {
                        $ctx->duplicateContentResponses++;
                    }
                } catch (Throwable $storageException) {
                    // Content indexing is advisory; a storage failure must not discard a successfully fetched page.
                    $this->recordError($ctx, $response->effectiveUrl, new RuntimeException(
                        'Unable to record response content fingerprint: ' . $storageException->getMessage(),
                        previous: $storageException,
                    ));
                }
            }

            // Parsing happens here, after the download settled and outside any transport callback.
            $page = $this->fetcher->parser()->parse($response, $options->encoding);
            foreach ($this->middlewares as $middleware) {
                $page = $middleware->afterFetch($request, $page);
            }
            if (!$this->hostAllowed($page->url(), $spider->allowedHosts())) {
                throw new RuntimeException('The final response URL is outside the spider host allow-list.');
            }
            if (!$spider->acceptsStatus($status)) {
                throw new HttpStatusException($status, $page->url());
            }
            $ctx->completed++;
            $ctx->visited[] = $page->url();

            foreach ($spider->parse($page, $request) as $output) {
                if ($output instanceof SpiderRequest) {
                    $this->scheduleChild($ctx, $spider, $output, $page, $request);
                    continue;
                }

                if ($output instanceof SpiderItem) {
                    $data = $output->data;
                } elseif (is_array($output)) {
                    $data = $output;
                } else {
                    throw new \UnexpectedValueException('Spider::parse() must yield SpiderRequest, SpiderItem, or item arrays.');
                }

                foreach ($this->pipelines as $pipeline) {
                    $data = $pipeline->process($data);
                    if ($data === null) {
                        break;
                    }
                }
                if ($data !== null) {
                    $ctx->itemsCount++;
                    if ($spider->collectItems()) {
                        $ctx->items[] = $data;
                    }
                }
            }
        } catch (Throwable $exception) {
            $this->fail($ctx, $request, $exception);
        }
    }

    /** Queue a non-blocking retry (the back-off elapses while other requests keep running). */
    private function scheduleRetry(CrawlContext $ctx, Spider $spider, QueuedRequest $item, ?Throwable $error, ?int $status, ?string $retryAfter = null): bool
    {
        $policy = $spider->retryPolicy() ?? $this->fetcher->defaultRetryPolicy();
        if ($policy === null || !$policy->retriesEnabled() || $item->attempt >= $policy->maxAttempts || !$policy->allowsMethod($item->request->method)) {
            return false;
        }
        if ($error !== null ? !$policy->shouldRetryException($error) : $status === null || !$policy->shouldRetryStatus($status)) {
            return false;
        }

        $ctx->delayed[] = [($this->clock)() + $policy->delayMs($item->attempt, $retryAfter), new QueuedRequest($item->request, $item->attempt + 1, true)];
        $ctx->retried++;

        return true;
    }

    private function promoteDueRetries(CrawlContext $ctx): void
    {
        if ($ctx->delayed === []) {
            return;
        }
        $now = ($this->clock)();
        $waiting = [];
        foreach ($ctx->delayed as $entry) {
            if ($entry[0] <= $now) {
                $ctx->ready->enqueue($entry[1]);
            } else {
                $waiting[] = $entry;
            }
        }
        $ctx->delayed = $waiting;
    }

    private function earliestRetryMs(CrawlContext $ctx): int
    {
        $times = array_map(static fn (array $entry): int => $entry[0], $ctx->delayed);

        return $times === [] ? ($this->clock)() : min($times);
    }

    /**
     * Per-request options if given, otherwise the scraper's defaults; request extras (such as a POST body) are
     * added on top, so they never replace inherited options. The spider's host allow-list is always intersected
     * with a request-level list, so individual requests can narrow but never widen the spider's policy.
     */
    private function effectiveOptions(Spider $spider, SpiderRequest $request): FetchOptions
    {
        $options = $request->options ?? $this->fetcher->defaultOptions();
        foreach ($request->extra as $name => $value) {
            $options = $options->withExtraOption((string) $name, $value);
        }
        if ($spider->allowedHosts() !== []) {
            // This policy object only contributes an allow-list; null size limit avoids imposing a new default cap.
            $options = $options->tightenedBy(new FetchOptions(maxBodyBytes: null, allowedHosts: $spider->allowedHosts()));
        }

        return $options;
    }

    private function awaitSlot(CrawlContext $ctx, Spider $spider, string $host): void
    {
        $delay = max($spider->requestDelayMs(), $spider->autoThrottle()?->delayMs($host) ?? 0);
        if ($delay > 0 && isset($ctx->lastStartMs[$host])) {
            $wait = $ctx->lastStartMs[$host] + $delay - ($this->clock)();
            if ($wait > 0) {
                ($this->sleeper)($wait);
            }
        }
        $ctx->lastStartMs[$host] = ($this->clock)();
    }

    private function fail(CrawlContext $ctx, SpiderRequest $request, Throwable $exception): void
    {
        foreach ($this->middlewares as $middleware) {
            try {
                $middleware->onError($request, $exception);
            } catch (Throwable $middlewareException) {
                $ctx->errors[] = [
                    'url' => UrlSanitizer::sanitize($request->url),
                    'message' => 'Downloader middleware error: ' . UrlSanitizer::sanitizeText($middlewareException->getMessage()),
                    'exception' => $middlewareException::class,
                ];
            }
        }
        $this->recordError($ctx, $request->url, $exception);
    }

    private function recordError(CrawlContext $ctx, string $url, Throwable $exception): void
    {
        // Messages from HTTP clients routinely embed the full URL; never store credentials or tokens.
        $ctx->errors[] = ['url' => UrlSanitizer::sanitize($url), 'message' => UrlSanitizer::sanitizeText($exception->getMessage()), 'exception' => $exception::class];
    }

    private function scheduleChild(CrawlContext $ctx, Spider $spider, SpiderRequest $child, Page $page, SpiderRequest $parent): void
    {
        $depth = $parent->depth + 1;
        if ($depth > $spider->maxDepth() || $ctx->scheduled >= $spider->maxPages()) {
            return;
        }

        try {
            $resolved = $page->resolve($child->url);
            $scheme = strtolower((new Uri($resolved))->getScheme());
        } catch (Throwable $exception) {
            // One malformed link must not discard the rest of the page's output.
            $ctx->errors[] = ['url' => UrlSanitizer::sanitize($child->url), 'message' => 'Skipped unparsable link: ' . UrlSanitizer::sanitizeText($exception->getMessage()), 'exception' => $exception::class];

            return;
        }

        // mailto:, tel:, javascript: ... are normal page content, not crawl targets; off-site links are
        // filtered silently instead of burning the page budget.
        if (!in_array($scheme, ['http', 'https'], true) || !$this->hostAllowed($resolved, $spider->allowedHosts())) {
            return;
        }

        $this->enqueue($ctx, $child->withUrl($resolved)->withDepth($depth), $spider->maxPages());
    }

    private function enqueue(CrawlContext $ctx, SpiderRequest $request, int $maxPages): bool
    {
        // Every scheduled request will be fetched, so scheduling more than maxPages can never help and would
        // only grow the queue without bound on large sites.
        if ($ctx->scheduled >= $maxPages || !$this->markSeen($ctx, $request)) {
            return false;
        }
        $ctx->ready->enqueue(new QueuedRequest($request));
        $ctx->scheduled++;

        return true;
    }

    private function markSeen(CrawlContext $ctx, SpiderRequest $request): bool
    {
        try {
            $fingerprint = RequestFingerprint::for($request);
        } catch (Throwable) {
            // Invalid URLs are still queued once so dispatch can report the useful validation error.
            $fingerprint = hash('sha256', strtoupper($request->method) . "\0" . trim($request->url));
        }

        // Restored checkpoints may contain identities from an earlier in-memory run, even if the
        // caller now resumes with SQLite. Check those exact values before consulting the external store.
        if (isset($ctx->seen[$fingerprint])) {
            return false;
        }

        if ($this->crawlDeduplicator !== null) {
            return !$this->crawlDeduplicator->markUrlSeen($ctx->dedupeScope, $fingerprint);
        }

        $ctx->seen[$fingerprint] = true;

        return true;
    }

    private function canonicalUrl(string $url): string
    {
        return (string) (new Uri(UrlNormalizer::normalize($url)))->withFragment('');
    }

    /** @param list<string> $allowedHosts */
    private function hostAllowed(string $url, array $allowedHosts): bool
    {
        return HostPolicy::allows($url, $allowedHosts);
    }

    private function save(CrawlContext $ctx, Spider $spider, JsonCrawlStateStore $state): void
    {
        $pending = [];
        foreach ($ctx->ready as $item) {
            $pending[] = $this->serializeItem($item);
        }
        foreach ($ctx->delayed as [, $item]) {
            $pending[] = $this->serializeItem($item);
        }
        // Requests still downloading are not done: they go back to the queue on resume.
        foreach ($ctx->inflight as $entry) {
            $pending[] = $this->serializeItem($entry['item']);
        }

        $state->save([
            'spider' => $spider::class,
            'saved_at' => gmdate('c'),
            'dedupe_scope' => $ctx->dedupeScope,
            'pending' => $pending,
            'seen' => array_keys($ctx->seen),
            'scheduled' => $ctx->scheduled,
            'attempted' => $ctx->attempted,
            'completed' => $ctx->completed,
            'items_count' => $ctx->itemsCount,
            'duplicate_content_responses' => $ctx->duplicateContentResponses,
        ]);
        $ctx->sinceCheckpoint = 0;
    }

    /** @return array<string, mixed> */
    private function serializeItem(QueuedRequest $item): array
    {
        $extra = [];
        $supportedExtraKeys = ['form_params', 'json', 'query', 'body'];
        foreach ($item->request->extra as $key => $value) {
            if (!is_string($key) || !in_array($key, $supportedExtraKeys, true)) {
                throw new RuntimeException(sprintf('Cannot checkpoint crawl: SpiderRequest extra option "%s" is not supported by resumable state.', (string) $key));
            }
            if (!FetchOptions::isJsonSafeValue($value)) {
                throw new RuntimeException(sprintf('Cannot checkpoint crawl: SpiderRequest extra option "%s" is not JSON-safe. Use scalar/array UTF-8 data or remove the resumable state store.', $key));
            }
            $extra[$key] = $value;
        }

        return [
            'url' => $item->request->url,
            'method' => $item->request->method,
            'depth' => $item->request->depth,
            'options' => $item->request->options?->toState(),
            'extra' => $extra,
            'attempt' => $item->attempt,
            'counted' => $item->counted,
        ];
    }

    private function restore(CrawlContext $ctx, Spider $spider, JsonCrawlStateStore $state): bool
    {
        $data = $state->load();
        if ($data === null) {
            return false;
        }
        if (($data['spider'] ?? null) !== $spider::class) {
            throw new RuntimeException(sprintf('Crawl state %s belongs to a different spider (%s). Delete it or use another state file.', $state->path(), is_string($data['spider'] ?? null) ? $data['spider'] : 'unknown'));
        }

        if (is_string($data['dedupe_scope'] ?? null) && $data['dedupe_scope'] !== '') {
            $ctx->dedupeScope = $data['dedupe_scope'];
        }

        foreach (is_array($data['pending'] ?? null) ? $data['pending'] : [] as $entry) {
            if (!is_array($entry) || !is_string($entry['url'] ?? null)) {
                continue;
            }
            $options = is_array($entry['options'] ?? null) ? FetchOptions::fromState($entry['options']) : null;
            $extra = is_array($entry['extra'] ?? null) ? array_intersect_key($entry['extra'], array_flip(['form_params', 'json', 'query', 'body'])) : [];
            $request = new SpiderRequest($entry['url'], is_string($entry['method'] ?? null) ? $entry['method'] : 'GET', $options, (int) ($entry['depth'] ?? 0), $extra);
            $ctx->ready->enqueue(new QueuedRequest($request, max(1, (int) ($entry['attempt'] ?? 1)), (bool) ($entry['counted'] ?? false)));
        }
        foreach (is_array($data['seen'] ?? null) ? $data['seen'] : [] as $hash) {
            if (is_string($hash)) {
                $ctx->seen[$hash] = true;
            }
        }
        $ctx->scheduled = (int) ($data['scheduled'] ?? 0);
        $ctx->attempted = (int) ($data['attempted'] ?? 0);
        $ctx->completed = (int) ($data['completed'] ?? 0);
        $ctx->itemsCount = (int) ($data['items_count'] ?? 0);
        $ctx->duplicateContentResponses = (int) ($data['duplicate_content_responses'] ?? 0);

        return true;
    }
}
