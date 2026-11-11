<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Support;

use HElturkey\WebTrawler\Contracts\HttpTransport;
use HElturkey\WebTrawler\Http\FetchResponse;

/** Blocking transport that records every request and answers from a map (never touches the network). */
final class RecordingTransport implements HttpTransport
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $calls = [];

    /**
     * @param array<string, array{int, string}> $responses url => [status, body]
     * @param array<string, string> $redirects url => effective (post-redirect) url
     */
    public function __construct(private array $responses = [], private array $redirects = [], private int $defaultStatus = 200)
    {
    }

    public function send(string $method, string $url, array $options = []): FetchResponse
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];
        [$status, $body] = $this->responses[$url] ?? [$this->defaultStatus, '<p>ok</p>'];

        return new FetchResponse($status, ['Content-Type' => ['text/html; charset=utf-8']], $body, $url, $this->redirects[$url] ?? $url, 1.0);
    }

    /** @return list<string> */
    public function urls(): array
    {
        return array_column($this->calls, 'url');
    }
}
