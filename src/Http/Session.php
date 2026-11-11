<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;
use HElturkey\WebTrawler\Parsing\Page;

/** Persistent cookie context for a sequence of requests. */
final class Session
{
    private readonly CookieJarInterface $cookies;

    public function __construct(private readonly Fetcher $fetcher, ?CookieJarInterface $cookies = null)
    {
        $this->cookies = $cookies ?? new CookieJar();
    }

    public function fetch(string $url, ?FetchOptions $options = null, string $method = 'GET'): Page
    {
        $sessionOptions = ($options ?? $this->fetcher->defaultOptions())->withExtraOption('cookies', $this->cookies);

        return $this->fetcher->fetch($url, $sessionOptions, $method);
    }

    public function get(string $url, ?FetchOptions $options = null): Page
    {
        return $this->fetch($url, $options, 'GET');
    }

    /** @param array<string, mixed> $data */
    public function post(string $url, array $data = [], ?FetchOptions $options = null): Page
    {
        return $this->fetch($url, ($options ?? $this->fetcher->defaultOptions())->withExtraOption('form_params', $data), 'POST');
    }

    public function cookies(): CookieJarInterface
    {
        return $this->cookies;
    }
}
