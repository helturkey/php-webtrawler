<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Spider;

use HElturkey\WebTrawler\Http\FetchOptions;

final readonly class SpiderRequest
{
    /**
     * @param FetchOptions|null $options Explicit per-request options. null means "use the scraper's defaults".
     * @param array<string, mixed> $extra Request-specific transport options (e.g. form_params) that are added
     *                                    at dispatch time on top of whatever options end up applying. Keeping
     *                                    them apart from $options is what lets a POST inherit the scraper's
     *                                    defaults and security policy instead of replacing them.
     */
    public function __construct(
        public string $url,
        public string $method = 'GET',
        public ?FetchOptions $options = null,
        public int $depth = 0,
        public array $extra = [],
    ) {
        if ($depth < 0) {
            throw new \InvalidArgumentException('Request depth cannot be negative.');
        }
    }

    public static function get(string $url, ?FetchOptions $options = null): self
    {
        return new self($url, 'GET', $options);
    }

    /** @param array<string, mixed> $data */
    public static function post(string $url, array $data, ?FetchOptions $options = null): self
    {
        return new self($url, 'POST', $options, 0, ['form_params' => $data]);
    }

    public function withDepth(int $depth): self
    {
        return new self($this->url, $this->method, $this->options, $depth, $this->extra);
    }

    public function withUrl(string $url): self
    {
        return new self($url, $this->method, $this->options, $this->depth, $this->extra);
    }
}
