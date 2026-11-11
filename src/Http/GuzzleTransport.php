<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use HElturkey\WebTrawler\Contracts\AsyncHttpTransport;
use HElturkey\WebTrawler\Exception\ResponseTooLargeException;
use HElturkey\WebTrawler\Exception\ScraperException;
use HElturkey\WebTrawler\Version;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use stdClass;
use Throwable;

final class GuzzleTransport implements AsyncHttpTransport
{
    private readonly ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client([
            'http_errors' => false,
            'headers' => ['User-Agent' => Version::userAgent()],
        ]);
    }

    public function supportsAsync(): bool
    {
        return true;
    }

    public function send(string $method, string $url, array $options = []): FetchResponse
    {
        $startedAt = hrtime(true);
        $url = UrlNormalizer::normalize($url);
        [$prepared, $state] = $this->prepare($url, $options);

        try {
            $response = $this->client->request(strtoupper($method), $url, $prepared);
        } catch (Throwable $exception) {
            throw $this->translate($exception, $state, $url);
        }

        return $this->toFetchResponse($response, $url, $state, (hrtime(true) - $startedAt) / 1_000_000);
    }

    public function sendAsync(string $method, string $url, array $options = []): PromiseInterface
    {
        $startedAt = hrtime(true);
        $url = UrlNormalizer::normalize($url);
        [$prepared, $state] = $this->prepare($url, $options);

        return $this->client->requestAsync(strtoupper($method), $url, $prepared)->then(
            fn (ResponseInterface $response): FetchResponse => $this->toFetchResponse($response, $url, $state, (hrtime(true) - $startedAt) / 1_000_000),
            function (mixed $reason) use ($state, $url): never {
                throw $this->translate($reason instanceof Throwable ? $reason : new \RuntimeException('Request failed.'), $state, $url);
            },
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: array<string, mixed>, 1: stdClass}
     */
    private function prepare(string $url, array $options): array
    {
        $state = new stdClass();
        $state->effectiveUrl = $url;
        $state->exceeded = false;
        $state->limit = 0;

        foreach (['timeout', 'connect_timeout'] as $timeoutName) {
            $timeout = $options[$timeoutName] ?? null;
            if ($timeout !== null && ((!is_int($timeout) && !is_float($timeout)) || !is_finite((float) $timeout) || $timeout < 0)) {
                throw new \InvalidArgumentException(sprintf('GuzzleTransport %s must be a finite non-negative number.', $timeoutName));
            }
        }

        $maxBodyBytes = $options['max_body_bytes'] ?? null;
        if ($maxBodyBytes !== null && (!is_int($maxBodyBytes) || $maxBodyBytes < 1)) {
            throw new \InvalidArgumentException('GuzzleTransport max_body_bytes must be a positive integer or null.');
        }
        $blockPrivate = $options['block_private_networks'] ?? false;
        if (!is_bool($blockPrivate)) {
            throw new \InvalidArgumentException('GuzzleTransport block_private_networks must be a boolean.');
        }
        $allowedHosts = $options['allowed_hosts'] ?? [];
        if (!is_array($allowedHosts) || !array_is_list($allowedHosts)) {
            throw new \InvalidArgumentException('GuzzleTransport allowed_hosts must be a list of non-empty host names.');
        }
        foreach ($allowedHosts as $host) {
            if (!is_string($host) || trim($host, ". \t") === '') {
                throw new \InvalidArgumentException('GuzzleTransport allowed_hosts must be a list of non-empty host names.');
            }
        }
        unset($options['max_body_bytes'], $options['block_private_networks'], $options['allowed_hosts']);

        // The transport is public and may be called without Fetcher; enforce the initial URL here too.
        HostPolicy::assert($state->effectiveUrl, $allowedHosts);
        if ($blockPrivate) {
            UrlGuard::assertPublic($state->effectiveUrl);
        }

        $userOnStats = $options['on_stats'] ?? null;
        $options['on_stats'] = static function (TransferStats $stats) use ($state, $userOnStats): void {
            $state->effectiveUrl = (string) $stats->getEffectiveUri();
            if (is_callable($userOnStats)) {
                $userOnStats($stats);
            }
        };

        if ($maxBodyBytes !== null) {
            $state->limit = $maxBodyBytes;
            $userOnHeaders = $options['on_headers'] ?? null;
            // Reject early when the server announces an oversized body ...
            $options['on_headers'] = static function (ResponseInterface $response) use ($maxBodyBytes, $state, $userOnHeaders): void {
                $length = $response->getHeaderLine('Content-Length');
                if (ctype_digit($length) && (int) $length > $maxBodyBytes) {
                    $state->exceeded = true;
                    throw new ResponseTooLargeException('Announced Content-Length exceeds the limit.');
                }
                if (is_callable($userOnHeaders)) {
                    $userOnHeaders($response);
                }
            };
            // ... and abort chunked/streaming downloads as soon as the limit is crossed. cURL surfaces our
            // exception directly; StreamHandler wraps it in a generic read error, so the $exceeded flag (not
            // the exception type) is what identifies an oversize abort.
            $userProgress = $options['progress'] ?? null;
            $options['progress'] = static function (int|float $downloadTotal, int|float $downloaded, int|float $uploadTotal = 0, int|float $uploaded = 0) use ($maxBodyBytes, $state, $userProgress): void {
                if ($downloaded > $maxBodyBytes) {
                    $state->exceeded = true;
                    throw new ResponseTooLargeException('Download exceeded the limit.');
                }
                if (is_callable($userProgress)) {
                    $userProgress($downloadTotal, $downloaded, $uploadTotal, $uploaded);
                }
            };
        }

        $allowRedirects = $options['allow_redirects'] ?? false;
        if (!is_bool($allowRedirects) && !is_array($allowRedirects)) {
            throw new \InvalidArgumentException('GuzzleTransport allow_redirects must be a boolean or an options array.');
        }
        if (is_array($allowRedirects) && isset($allowRedirects['protocols'])) {
            $protocols = $allowRedirects['protocols'];
            if (!is_array($protocols) || !array_is_list($protocols) || $protocols === []) {
                throw new \InvalidArgumentException('GuzzleTransport allow_redirects.protocols must be a non-empty list containing only http and https.');
            }
            foreach ($protocols as $protocol) {
                if (!is_string($protocol) || !in_array(strtolower($protocol), ['http', 'https'], true)) {
                    throw new \InvalidArgumentException('GuzzleTransport allow_redirects.protocols must be a non-empty list containing only http and https.');
                }
            }
        }
        if (($blockPrivate || $allowedHosts !== []) && $allowRedirects !== false) {
            // A boolean true delegates redirects without a hook. Convert it to Guzzle's options form so
            // the policy callback runs before every followed hop.
            $allowRedirects = is_array($allowRedirects) ? $allowRedirects : ['max' => 10];
            $userOnRedirect = $allowRedirects['on_redirect'] ?? null;
            $allowRedirects['on_redirect'] = static function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use ($userOnRedirect, $blockPrivate, $allowedHosts): void {
                // Checked before the hop is followed, so a disallowed target is never contacted.
                HostPolicy::assert((string) $uri, $allowedHosts);
                if ($blockPrivate) {
                    UrlGuard::assertPublic((string) $uri);
                }
                if (is_callable($userOnRedirect)) {
                    $userOnRedirect($request, $response, $uri);
                }
            };
            $options['allow_redirects'] = $allowRedirects;
        }

        return [$options, $state];
    }

    private function translate(Throwable $exception, stdClass $state, string $url): Throwable
    {
        if ($state->exceeded === true && is_int($state->limit) && $state->limit > 0) {
            return ResponseTooLargeException::forLimit($state->limit, $url);
        }

        // Guzzle wraps exceptions thrown from on_headers; surface our own domain errors unchanged.
        if ($exception instanceof RequestException && $exception->getPrevious() instanceof ScraperException) {
            return $exception->getPrevious();
        }

        return $exception;
    }

    private function toFetchResponse(ResponseInterface $response, string $requestedUrl, stdClass $state, float $durationMs): FetchResponse
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = array_values($values);
        }

        return new FetchResponse(
            status: $response->getStatusCode(),
            headers: $headers,
            body: (string) $response->getBody(),
            requestedUrl: $requestedUrl,
            effectiveUrl: is_string($state->effectiveUrl) ? $state->effectiveUrl : $requestedUrl,
            durationMs: $durationMs,
        );
    }
}
