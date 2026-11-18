<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Transport;

use HElturkey\WebTrawler\Contracts\Http\TransportInterface;
use HElturkey\WebTrawler\Exception\NetworkException;
use HElturkey\WebTrawler\Response\FetchResponse;
use HElturkey\WebTrawler\Support\UrlSanitizer;
use InvalidArgumentException;

/**
 * Development cache: remembers GET responses on disk so a `parse()` method can be rewritten and re-run
 * against the same pages without touching the network, and so a bug can be replayed offline.
 *
 * It is a development tool, not an HTTP cache: it ignores Cache-Control, caches any status below 500 except 429,
 * and never revalidates. Do not enable it in production. Cached responses may contain cookies and tokens, so
 * the directory is created 0700 and entries 0600; keep it out of version control.
 *
 * It is blocking only (it does not implement the async contract), so a concurrent spider needs a transport
 * without this wrapper. The Downloader still validates every response, cached or not.
 */
final class CachingTransport implements TransportInterface
{
    private const FORMAT = 1;

    /**
     * @param int|null $ttlSeconds Entries older than this are refetched; null keeps them until cleared.
     * @param bool $offline Never touch the network: a missing entry is an error. Use it to replay a crawl.
     * @param int $maxEntryBytes Larger bodies are passed through without being stored.
     */
    public function __construct(
        private readonly TransportInterface $inner,
        private readonly string $directory,
        private readonly ?int $ttlSeconds = null,
        private readonly bool $offline = false,
        private readonly int $maxEntryBytes = 20_971_520,
    ) {
        if ($directory === '' || ($ttlSeconds !== null && $ttlSeconds < 0) || $maxEntryBytes < 1) {
            throw new InvalidArgumentException('The cache needs a directory, a non-negative TTL and a positive size limit.');
        }
    }

    public function send(string $method, string $url, array $options = []): FetchResponse
    {
        $method = strtoupper($method);
        $key = $method === 'GET' ? $this->keyFor($url, $options) : null;

        if ($key === null) {
            if ($this->offline) {
                throw new NetworkException('Offline dev cache cannot replay this request (only plain GET requests are cached).');
            }

            return $this->inner->send($method, $url, $options);
        }

        $cached = $this->read($key, $url);
        if ($cached !== null) {
            return $cached;
        }
        if ($this->offline) {
            throw new NetworkException('Dev cache miss in offline mode: ' . UrlSanitizer::sanitize($url));
        }

        $response = $this->inner->send($method, $url, $options);
        if ($response->status < 500 && $response->status !== 429 && strlen($response->body) <= $this->maxEntryBytes) {
            $this->write($key, $response);
        }

        return $response;
    }

    /** Delete every cached entry; returns how many were removed. */
    public function clear(): int
    {
        $removed = 0;
        foreach (glob(rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (is_file($file) && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /** @param array<string, mixed> $options */
    private function keyFor(string $url, array $options): ?string
    {
        // Only inputs that change what the server returns take part in the key; timeouts and limits do not.
        $relevant = [];
        foreach (['headers', 'query', 'cookies', 'allow_redirects', 'allowed_hosts', 'block_private_networks', 'proxy'] as $name) {
            if (array_key_exists($name, $options)) {
                $relevant[$name] = $options[$name];
            }
        }
        // Anything that does not serialize cleanly (objects, streams) makes the request uncacheable.
        $encoded = json_encode($relevant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return null;
        }
        foreach (['body', 'json', 'form_params', 'multipart', 'sink', 'stream'] as $payload) {
            if (array_key_exists($payload, $options)) {
                return null;
            }
        }

        return hash('sha256', $url . "\0" . $encoded);
    }

    private function read(string $key, string $url): ?FetchResponse
    {
        $file = $this->path($key);
        if (!is_file($file) || filesize($file) > $this->maxEntryBytes * 2) {
            return null;
        }
        $json = @file_get_contents($file);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT
            || !is_int($data['status'] ?? null) || !is_array($data['headers'] ?? null)
            || !is_string($data['body'] ?? null) || !is_string($data['effective_url'] ?? null) || !is_int($data['stored_at'] ?? null)) {
            return null;
        }
        if ($this->ttlSeconds !== null && $data['stored_at'] + $this->ttlSeconds < time()) {
            return null;
        }
        $body = base64_decode($data['body'], true);
        if ($body === false) {
            return null;
        }

        $headers = [];
        foreach ($data['headers'] as $name => $values) {
            if (is_string($name) && is_array($values)) {
                $headers[$name] = array_values(array_filter($values, 'is_string'));
            }
        }

        return new FetchResponse((int) $data['status'], $headers, $body, $url, $data['effective_url'], 0.0, ['cache' => 'hit']);
    }

    private function write(string $key, FetchResponse $response): void
    {
        $directory = rtrim($this->directory, '/\\');
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }

        $json = json_encode([
            'format' => self::FORMAT,
            'status' => $response->status,
            'headers' => $response->headers,
            'body' => base64_encode($response->body),
            'effective_url' => $response->effectiveUrl,
            'stored_at' => time(),
        ], JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        // Write to a private temporary file and rename it, so a crash never leaves a half-written entry.
        $temporary = $directory . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(8));
        $previous = umask(0077);
        try {
            $written = @file_put_contents($temporary, $json, LOCK_EX);
        } finally {
            umask($previous);
        }
        if ($written === false || !@rename($temporary, $this->path($key))) {
            @unlink($temporary);
        }
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $key . '.json';
    }
}
