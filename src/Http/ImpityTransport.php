<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

use Dekor\Impity\Client;
use Dekor\Impity\ImpityBrowser;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use HElturkey\WebTrawler\Contracts\HttpTransport;
use HElturkey\WebTrawler\Exception\ResponseTooLargeException;
use HElturkey\WebTrawler\Exception\ScraperException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * Optional TLS/HTTP2 browser-impersonating transport backed by dekor/php-impity.
 * Redirects are followed here, not by the underlying client, so every hop can be checked before it is sent.
 * The implementation intentionally supports only the request options documented below.
 */
final class ImpityTransport implements HttpTransport
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(private readonly mixed $browser = null)
    {
    }

    public function send(string $method, string $url, array $options = []): FetchResponse
    {
        if (!class_exists(Client::class) || !enum_exists(ImpityBrowser::class)) {
            throw new RuntimeException('php-impity is not installed. Run: composer require dekor/php-impity');
        }

        $browser = $this->browser ?? constant(ImpityBrowser::class . '::DEFAULT');
        if (!$browser instanceof ImpityBrowser) {
            throw new \InvalidArgumentException('The browser profile must be a Dekor\\Impity\\ImpityBrowser enum value.');
        }

        foreach (['multipart', 'sink', 'stream', 'auth'] as $unsupported) {
            if (isset($options[$unsupported])) {
                throw new \InvalidArgumentException(sprintf('ImpityTransport does not support the "%s" option.', $unsupported));
            }
        }

        $headers = $options['headers'] ?? [];
        if (!is_array($headers)) {
            throw new \InvalidArgumentException('HTTP headers must be an array.');
        }

        $proxy = self::proxyOption($options['proxy'] ?? null);
        $allowedHosts = self::allowedHosts($options);
        $redirectOptions = $options['allow_redirects'] ?? true;
        $strictRedirects = false;
        $sendReferer = true;
        $allowedRedirectSchemes = ['http', 'https'];
        if (is_bool($redirectOptions)) {
            $maxRedirects = $redirectOptions ? 10 : 0;
        } elseif (is_array($redirectOptions)) {
            $maxRedirects = $redirectOptions['max'] ?? 10;
            if (!is_int($maxRedirects) || $maxRedirects < 0) {
                throw new \InvalidArgumentException('ImpityTransport allow_redirects.max must be a non-negative integer.');
            }
            foreach (['strict', 'referer', 'track_redirects'] as $booleanOption) {
                if (array_key_exists($booleanOption, $redirectOptions) && !is_bool($redirectOptions[$booleanOption])) {
                    throw new \InvalidArgumentException(sprintf('ImpityTransport allow_redirects.%s must be a boolean.', $booleanOption));
                }
            }
            if (($redirectOptions['track_redirects'] ?? false) === true) {
                throw new \InvalidArgumentException('ImpityTransport does not support allow_redirects.track_redirects.');
            }
            $strictRedirects = $redirectOptions['strict'] ?? false;
            $sendReferer = $redirectOptions['referer'] ?? true;
            if (isset($redirectOptions['protocols'])) {
                $protocols = $redirectOptions['protocols'];
                if (!is_array($protocols) || !array_is_list($protocols) || $protocols === []) {
                    throw new \InvalidArgumentException('ImpityTransport allow_redirects.protocols must be a non-empty list containing only http and https.');
                }
                $allowedRedirectSchemes = [];
                foreach ($protocols as $scheme) {
                    if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
                        throw new \InvalidArgumentException('ImpityTransport allow_redirects.protocols must be a non-empty list containing only http and https.');
                    }
                    $allowedRedirectSchemes[] = strtolower($scheme);
                }
                $allowedRedirectSchemes = array_values(array_unique($allowedRedirectSchemes));
            }
        } else {
            throw new \InvalidArgumentException('ImpityTransport allow_redirects must be a boolean or an options array.');
        }
        $followRedirects = $maxRedirects > 0;
        $maxBodyOption = $options['max_body_bytes'] ?? null;
        if ($maxBodyOption !== null && (!is_int($maxBodyOption) || $maxBodyOption < 1)) {
            throw new \InvalidArgumentException('The maximum response body size must be a positive integer or null.');
        }
        $maxBody = $maxBodyOption;
        $blockPrivate = $options['block_private_networks'] ?? false;
        if (!is_bool($blockPrivate)) {
            throw new \InvalidArgumentException('ImpityTransport block_private_networks must be a boolean.');
        }
        $verify = $options['verify'] ?? true;
        if (!is_bool($verify) && !is_string($verify)) {
            throw new \InvalidArgumentException('ImpityTransport verify must be a boolean or a CA bundle path.');
        }
        foreach (['timeout', 'connect_timeout'] as $timeoutName) {
            $timeout = $options[$timeoutName] ?? null;
            if ($timeout !== null && ((!is_int($timeout) && !is_float($timeout)) || !is_finite((float) $timeout) || $timeout < 0)) {
                throw new \InvalidArgumentException(sprintf('ImpityTransport %s must be a finite non-negative number.', $timeoutName));
            }
        }
        if (isset($options['cookies']) && !$options['cookies'] instanceof CookieJarInterface) {
            throw new \InvalidArgumentException('ImpityTransport cookies must be a Guzzle CookieJar instance.');
        }

        if (isset($options['query'])) {
            $query = $options['query'];
            if (!is_array($query) && !is_string($query)) {
                throw new \InvalidArgumentException('The query request option must be an array or string.');
            }
            $queryString = is_array($query) ? http_build_query($query) : ltrim($query, '?');
            if ($queryString !== '') {
                // Uri::withQuery inserts the query before a fragment, unlike string concatenation.
                $uri = new Uri($url);
                $existingQuery = $uri->getQuery();
                $url = (string) $uri->withQuery($existingQuery === '' ? $queryString : $existingQuery . '&' . $queryString);
            }
        }

        $method = strtoupper($method);
        $url = UrlNormalizer::normalize($url);
        $requestedUrl = $url;
        $currentUrl = $url;
        $body = $this->buildBody($options, $headers);
        $clientOptions = [];
        foreach (['timeout', 'connect_timeout', 'cookies'] as $name) {
            if (array_key_exists($name, $options)) {
                $clientOptions[$name] = $options[$name];
            }
        }
        $clientOptions['verify'] = $verify;
        if ($proxy !== null) {
            $clientOptions['proxy'] = $proxy;
        }
        // Do not delegate redirects: the underlying client would follow them before our SSRF/host checks run.
        $clientOptions['allow_redirects'] = false;
        $clientOptions['http_errors'] = false;

        try {
            $client = new Client($browser, $clientOptions);
        } catch (Throwable $exception) {
            throw self::safeTransportException($exception);
        }

        $startedAt = hrtime(true);
        $status = 0;
        $responseHeaders = [];
        $responseBody = '';
        $effectiveUrl = $requestedUrl;

        for ($hop = 0; ; $hop++) {
            HostPolicy::assert($currentUrl, $allowedHosts);
            if ($blockPrivate) {
                UrlGuard::assertPublic($currentUrl);
            }

            if ($hop > 0 && $body !== null && $body->isSeekable()) {
                $body->rewind();
            }

            $request = new Request($method, $currentUrl, $headers, $body);
            try {
                $response = $client->sendRequest($request);
            } catch (Throwable $exception) {
                throw self::safeTransportException($exception);
            }

            $status = $response->getStatusCode();
            $location = $response->getHeaderLine('Location');
            if (!$followRedirects || !in_array($status, self::REDIRECT_STATUSES, true) || $location === '') {
                $responseHeaders = self::headers($response);
                $responseBody = self::readBody($response, $maxBody, $requestedUrl);
                $effectiveUrl = $currentUrl;
                break;
            }

            try {
                if ($hop >= $maxRedirects) {
                    $response->getBody()->close();
                    throw new ScraperException(sprintf('Too many redirects (limit %d).', $maxRedirects));
                }

                $next = UriResolver::resolve(new Uri($currentUrl), new Uri($location));
                if (!in_array(strtolower($next->getScheme()), $allowedRedirectSchemes, true) || $next->getHost() === '') {
                    throw new ScraperException('Refusing to follow a redirect whose scheme or host is not allowed.');
                }
                $nextUrl = UrlNormalizer::normalize((string) $next);
                HostPolicy::assert($nextUrl, $allowedHosts);
                if ($blockPrivate) {
                    UrlGuard::assertPublic($nextUrl);
                }
            } catch (Throwable $exception) {
                $response->getBody()->close();
                throw $exception;
            }

            if (!$strictRedirects && $status === 303 && $method !== 'HEAD') {
                $method = 'GET';
                $body = null;
                $headers = self::withoutHeaders($headers, ['content-length', 'content-type', 'transfer-encoding']);
            } elseif (!$strictRedirects && ($status === 301 || $status === 302) && $method === 'POST') {
                $method = 'GET';
                $body = null;
                $headers = self::withoutHeaders($headers, ['content-length', 'content-type', 'transfer-encoding']);
            } elseif ($body !== null && !$body->isSeekable()) {
                $response->getBody()->close();
                throw new ScraperException('Cannot replay a non-seekable request body after an HTTP redirect.');
            }

            if (!$sendReferer) {
                $headers = self::withoutHeaders($headers, ['referer']);
            }
            if (!UrlOrigin::same($currentUrl, $nextUrl)) {
                $headers = self::withoutHeaders($headers, ['authorization', 'cookie', 'proxy-authorization', 'host', 'referer']);
            }

            // Do not retain redirect response bodies while issuing the next request.
            $response->getBody()->close();
            $currentUrl = $nextUrl;
        }

        return new FetchResponse(
            status: $status,
            headers: $responseHeaders,
            body: $responseBody,
            requestedUrl: $requestedUrl,
            effectiveUrl: $effectiveUrl,
            durationMs: (hrtime(true) - $startedAt) / 1_000_000,
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private static function allowedHosts(array $options): array
    {
        $hosts = $options['allowed_hosts'] ?? [];
        if (!is_array($hosts) || !array_is_list($hosts)) {
            throw new \InvalidArgumentException('The allowed_hosts option must be a list of host names.');
        }

        $validated = [];
        foreach ($hosts as $host) {
            if (!is_string($host) || trim($host, ". \t") === '') {
                throw new \InvalidArgumentException('The allowed_hosts option must contain only non-empty host names.');
            }
            $validated[] = $host;
        }

        return $validated;
    }

    /** @return string|array<string, mixed>|null */
    private static function proxyOption(mixed $proxy): string|array|null
    {
        if ($proxy === null || is_string($proxy)) {
            return $proxy;
        }
        if (!is_array($proxy)) {
            throw new \InvalidArgumentException('ImpityTransport proxy must be a URL or a Guzzle-compatible proxy array.');
        }

        foreach ($proxy as $key => $value) {
            if (!in_array($key, ['http', 'https', 'no'], true)) {
                throw new \InvalidArgumentException(sprintf('ImpityTransport does not support proxy option "%s".', (string) $key));
            }
            if ($key === 'no') {
                if (!is_array($value) || !array_is_list($value) || array_filter($value, 'is_string') !== $value) {
                    throw new \InvalidArgumentException('The proxy "no" option must be a list of host patterns.');
                }
            } elseif (!is_string($value) || $value === '') {
                throw new \InvalidArgumentException(sprintf('The proxy "%s" option must be a non-empty URL.', $key));
            }
        }

        return $proxy;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $headers
     */
    private function buildBody(array $options, array &$headers): ?StreamInterface
    {
        if (isset($options['json'])) {
            if (!is_array($options['json']) && !is_object($options['json'])) {
                throw new \InvalidArgumentException('The json request option must be an array or object.');
            }
            self::defaultHeader($headers, 'Content-Type', 'application/json');

            return Utils::streamFor(json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        if (isset($options['form_params'])) {
            if (!is_array($options['form_params'])) {
                throw new \InvalidArgumentException('The form_params request option must be an array.');
            }
            self::defaultHeader($headers, 'Content-Type', 'application/x-www-form-urlencoded');

            return Utils::streamFor(http_build_query($options['form_params']));
        }

        if (array_key_exists('body', $options) && $options['body'] !== null) {
            return Utils::streamFor($options['body']);
        }

        return null;
    }

    /** @return array<string, list<string>> */
    private static function headers(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = array_values($values);
        }

        return $headers;
    }

    private static function readBody(ResponseInterface $response, ?int $maximumBytes, string $url): string
    {
        $stream = $response->getBody();
        try {
            $announcedLength = $response->getHeaderLine('Content-Length');
            if ($maximumBytes !== null && ctype_digit($announcedLength) && (int) $announcedLength > $maximumBytes) {
                throw ResponseTooLargeException::forLimit($maximumBytes, $url);
            }
            $size = $stream->getSize();
            if ($maximumBytes !== null && $size !== null && $size > $maximumBytes) {
                throw ResponseTooLargeException::forLimit($maximumBytes, $url);
            }

            $body = '';
            while (true) {
                $remaining = $maximumBytes === null ? 8192 : min(8192, $maximumBytes + 1 - strlen($body));
                if ($remaining <= 0) {
                    throw ResponseTooLargeException::forLimit($maximumBytes, $url);
                }

                $chunk = $stream->read($remaining);
                if (strlen($chunk) === 0) {
                    if ($stream->eof()) {
                        break;
                    }
                    throw new ScraperException('Impity response stream stopped before EOF.');
                }
                $body .= $chunk;
                if ($maximumBytes !== null && strlen($body) > $maximumBytes) {
                    throw ResponseTooLargeException::forLimit($maximumBytes, $url);
                }
            }

            return $body;
        } finally {
            $stream->close();
        }
    }

    /** @param array<string, mixed> $headers */
    private static function defaultHeader(array &$headers, string $name, string $value): void
    {
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp((string) $existing, $name) === 0) {
                return;
            }
        }
        $headers[$name] = $value;
    }

    /**
     * @param array<string, mixed> $headers
     * @param list<string> $remove
     * @return array<string, mixed>
     */
    private static function withoutHeaders(array $headers, array $remove): array
    {
        foreach (array_keys($headers) as $name) {
            if (in_array(strtolower((string) $name), $remove, true)) {
                unset($headers[$name]);
            }
        }

        return $headers;
    }

    private static function safeTransportException(Throwable $exception): Throwable
    {
        // Upstream exception messages can include request URLs with credentials or tokens.
        $message = \HElturkey\WebTrawler\Support\UrlSanitizer::sanitizeText($exception->getMessage());
        if ($message === '') {
            $message = 'The Impity HTTP request failed.';
        }

        return new ScraperException('Impity transport failed: ' . $message, (int) $exception->getCode(), $exception);
    }
}
