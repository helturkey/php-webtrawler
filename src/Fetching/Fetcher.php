<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Fetching;

use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Retry\RetryPolicy;
use HElturkey\WebTrawler\Policy\RequestPolicy;
use HElturkey\WebTrawler\Policy\BrowserOptionsResolver;
use HElturkey\WebTrawler\Response\FetchResponse;
use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Concurrency\Pending;
use HElturkey\WebTrawler\Contracts\Http\TransportInterface;
use HElturkey\WebTrawler\Contracts\ResponseParser;
use HElturkey\WebTrawler\Contracts\SnapshotStore;
use HElturkey\WebTrawler\Parsing\HtmlPageParser;
use HElturkey\WebTrawler\Parsing\Page;

/**
 * Download + parse for callers that want a Page. It only composes a Downloader and a ResponseParser;
 * transport, policy and HTML handling each live in their own class, and the spider runner uses the two
 * halves separately.
 */
final readonly class Fetcher
{
    public function __construct(
        private Downloader $downloader,
        private ResponseParser $parser,
    ) {
    }

    /** The common wiring: one transport, the default HTML parser, one set of security defaults. */
    public static function create(
        TransportInterface $transport,
        SnapshotStore $snapshotStore,
        AdaptiveMatcher $adaptiveMatcher,
        ?FetchOptions $defaultOptions = null,
        bool $enforceDefaults = false,
        ?RetryPolicy $defaultRetryPolicy = null,
    ): self {
        return new self(
            new Downloader($transport, new RequestPolicy($defaultOptions, $enforceDefaults), $defaultRetryPolicy),
            new HtmlPageParser($snapshotStore, $adaptiveMatcher),
        );
    }

    public function downloader(): Downloader
    {
        return $this->downloader;
    }

    public function parser(): ResponseParser
    {
        return $this->parser;
    }

    public function defaultOptions(): FetchOptions
    {
        return $this->downloader->defaultOptions();
    }

    public function defaultRetryPolicy(): ?RetryPolicy
    {
        return $this->downloader->defaultRetryPolicy();
    }

    public function supportsAsync(): bool
    {
        return $this->downloader->supportsAsync();
    }

    /**
     * @param array<string, mixed> $options
     * @phpstan-return array<string, mixed>
     */
    public function browserOptions(array $options): array
    {
        return (new BrowserOptionsResolver())->resolve($options, $this->downloader->policy());
    }

    public function fetch(string $url, ?FetchOptions $options = null, string $method = 'GET', bool $applyDefaultRetries = true): Page
    {
        $options = $this->downloader->resolveOptions($options);

        return $this->parser->parse($this->downloader->download($url, $options, $method, $applyDefaultRetries), $options->encoding);
    }

    /**
     * Start a download and parse it when it settles.
     *
     * @return Pending Resolves to a Page.
     */
    public function fetchAsync(string $url, ?FetchOptions $options = null, string $method = 'GET'): Pending
    {
        $options = $this->downloader->resolveOptions($options);

        return $this->downloader->downloadAsync($url, $options, $method)
            ->then(fn (mixed $response): Page => $this->parser->parse($response instanceof FetchResponse ? $response : throw new \UnexpectedValueException('Expected a FetchResponse.'), $options->encoding));
    }

    public function advance(?float $timeoutSeconds = null): void
    {
        $this->downloader->advance($timeoutSeconds);
    }

    public function get(string $url, ?FetchOptions $options = null): Page
    {
        return $this->fetch($url, $options, 'GET');
    }

    /** @param array<string, mixed> $data */
    public function post(string $url, array $data = [], ?FetchOptions $options = null): Page
    {
        return $this->fetch($url, ($options ?? $this->downloader->defaultOptions())->withExtraOption('form_params', $data), 'POST');
    }
}
