<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Spider;

use HElturkey\WebTrawler\Options\FetchOptions;

final readonly class SpiderRequest
{
    /**
     * @param FetchOptions|null $options Explicit per-request options. null means "use the scraper's defaults".
     * @param array<string, mixed> $extra Request-specific transport options (e.g. form_params) that are added
     *                                    at dispatch time on top of whatever options end up applying. Keeping
     *                                    them apart from $options is what lets a POST inherit the scraper's
     *                                    defaults and security policy instead of replacing them.
     * @param string|null $session Name of the spider session (a differently configured transport, such as a
     *                             browser) that must download this request. null uses the scraper's main transport.
     */
    public function __construct(
        public string $url,
        public string $method = 'GET',
        public ?FetchOptions $options = null,
        public int $depth = 0,
        public array $extra = [],
        public ?string $session = null,
    ) {
        if ($depth < 0) {
            throw new \InvalidArgumentException('Request depth cannot be negative.');
        }
        if ($session !== null && preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $session) !== 1) {
            throw new \InvalidArgumentException('A session name uses lowercase letters, digits, ".", "-" and "_" and starts with a letter.');
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
        return new self($this->url, $this->method, $this->options, $depth, $this->extra, $this->session);
    }

    public function withUrl(string $url): self
    {
        return new self($url, $this->method, $this->options, $this->depth, $this->extra, $this->session);
    }

    /**
     * The same request, downloaded by another named session. Use it to escalate a blocked page from plain HTTP
     * to a browser: `yield $request->viaSession('browser');` from Spider::onBlocked(). The session is part of
     * the request's identity, so the escalated request is not discarded as a duplicate of the original.
     */
    public function viaSession(?string $session): self
    {
        return new self($this->url, $this->method, $this->options, $this->depth, $this->extra, $session);
    }
}
