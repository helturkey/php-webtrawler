<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

use Closure;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use HElturkey\WebTrawler\Contracts\HttpTransport;
use HElturkey\WebTrawler\Exception\NetworkException;
use HElturkey\WebTrawler\Exception\ResponseTooLargeException;
use HElturkey\WebTrawler\Exception\ScraperException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Transport backed by the php-rnet extension (Rust/wreq: browser TLS and HTTP/2 fingerprints).
 *
 * Verified against php-rnet 0.0.2. Differences from GuzzleTransport worth knowing:
 *  - Redirects are followed here (not by the extension) so that every hop is validated, credentials are
 *    dropped on cross-host redirects and the effective URL is known.
 *  - The extension cannot stream: an announced Content-Length above the limit is rejected before the body
 *    is read, but a chunked body is fully downloaded before the limit can be applied.
 *  - Response headers are single-valued (duplicate headers such as Set-Cookie collapse to the last one).
 *    Use the "cookies" option (a cookie jar marks the client as cookie-storing) for sessions.
 *  - The "cookies" jar is only a switch: cookies live inside the extension's client, not in the jar.
 *  - Sync only: no concurrent requests.
 */
final class RnetTransport implements HttpTransport
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /** @var array<string, object> */
    private array $clients = [];

    private readonly ?string $emulation;

    /** @var (Closure(array<string, mixed>): object)|null */
    private readonly ?Closure $clientFactory;

    /**
     * @param string|null $emulation An RNet\Emulation constant name ("CHROME_136") or value.
     * @param (callable(array<string, mixed>): object)|null $clientFactory Test seam; receives the client
     *        configuration and returns an object with request(string $method, string $url, array $options).
     */
    public function __construct(?string $emulation = null, ?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory !== null ? Closure::fromCallable($clientFactory) : null;
        $this->emulation = self::resolveEmulation($emulation, $this->clientFactory !== null);
    }

    public static function isAvailable(): bool
    {
        return class_exists('RNet\ClientBuilder');
    }

    public function send(string $method, string $url, array $options = []): FetchResponse
    {
        foreach (['multipart', 'sink', 'stream', 'auth'] as $unsupported) {
            if (isset($options[$unsupported])) {
                throw new InvalidArgumentException(sprintf('RnetTransport does not support the "%s" option.', $unsupported));
            }
        }

        $startedAt = hrtime(true);
        $maxBody = $options['max_body_bytes'] ?? null;
        if ($maxBody !== null && (!is_int($maxBody) || $maxBody < 1)) {
            throw new InvalidArgumentException('RnetTransport max_body_bytes must be a positive integer or null.');
        }
        $blockPrivateOption = $options['block_private_networks'] ?? false;
        if (!is_bool($blockPrivateOption)) {
            throw new InvalidArgumentException('RnetTransport block_private_networks must be a boolean.');
        }
        $blockPrivate = $blockPrivateOption;
        foreach (['timeout', 'connect_timeout'] as $timeoutName) {
            $timeout = $options[$timeoutName] ?? null;
            if ($timeout !== null && ((!is_int($timeout) && !is_float($timeout)) || !is_finite((float) $timeout) || $timeout < 0)) {
                throw new InvalidArgumentException(sprintf('RnetTransport %s must be a finite non-negative number.', $timeoutName));
            }
        }
        $verify = $options['verify'] ?? true;
        if (!is_bool($verify)) {
            throw new InvalidArgumentException('RnetTransport supports boolean TLS verification only; custom CA paths are not supported.');
        }
        if (isset($options['cookies']) && !$options['cookies'] instanceof CookieJarInterface) {
            throw new InvalidArgumentException('RnetTransport cookies must be a Guzzle CookieJar instance.');
        }
        $allowedHostsOption = $options['allowed_hosts'] ?? [];
        if (!is_array($allowedHostsOption) || !array_is_list($allowedHostsOption)) {
            throw new InvalidArgumentException('RnetTransport allowed_hosts must be a list of non-empty host names.');
        }
        $allowedHosts = [];
        foreach ($allowedHostsOption as $host) {
            if (!is_string($host)) {
                throw new InvalidArgumentException('RnetTransport allowed_hosts must be a list of non-empty host names.');
            }
            $host = trim($host, ". \t");
            if ($host === '') {
                throw new InvalidArgumentException('RnetTransport allowed_hosts must be a list of non-empty host names.');
            }
            $allowedHosts[] = $host;
        }
        $redirects = $options['allow_redirects'] ?? true;
        $strictRedirects = false;
        $sendReferer = true;
        $allowedRedirectSchemes = ['http', 'https'];
        if (is_array($redirects)) {
            $maxRedirects = $redirects['max'] ?? 10;
            if (!is_int($maxRedirects) || $maxRedirects < 0) {
                throw new InvalidArgumentException('RnetTransport allow_redirects.max must be a non-negative integer.');
            }
            foreach (['strict', 'referer', 'track_redirects'] as $booleanOption) {
                if (array_key_exists($booleanOption, $redirects) && !is_bool($redirects[$booleanOption])) {
                    throw new InvalidArgumentException(sprintf('RnetTransport allow_redirects.%s must be a boolean.', $booleanOption));
                }
            }
            if (($redirects['track_redirects'] ?? false) === true) {
                throw new InvalidArgumentException('RnetTransport does not support allow_redirects.track_redirects.');
            }
            $strictRedirects = $redirects['strict'] ?? false;
            $sendReferer = $redirects['referer'] ?? true;
            if (isset($redirects['protocols'])) {
                $protocols = $redirects['protocols'];
                if (!is_array($protocols) || !array_is_list($protocols) || $protocols === []) {
                    throw new InvalidArgumentException('RnetTransport allow_redirects.protocols must be a non-empty list containing only http and https.');
                }
                $allowedRedirectSchemes = [];
                foreach ($protocols as $scheme) {
                    if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
                        throw new InvalidArgumentException('RnetTransport allow_redirects.protocols must be a non-empty list containing only http and https.');
                    }
                    $allowedRedirectSchemes[] = strtolower($scheme);
                }
                $allowedRedirectSchemes = array_values(array_unique($allowedRedirectSchemes));
            }
        } elseif (is_bool($redirects)) {
            $maxRedirects = $redirects ? 10 : 0;
        } else {
            throw new InvalidArgumentException('RnetTransport allow_redirects must be a boolean or an options array.');
        }

        $request = $this->requestOptions($options);
        $method = strtoupper($method);
        $current = UrlNormalizer::normalize(self::appendQuery($url, $options['query'] ?? null));
        HostPolicy::assert($current, $allowedHosts);
        if ($blockPrivate) {
            UrlGuard::assertPublic($current);
        }
        $client = $this->client($options);

        for ($hop = 0; ; $hop++) {
            $response = $this->call($client, $method, $current, $request);
            $status = (int) self::invoke($response, 'status');
            $location = $this->header($response, 'location');

            if ($maxRedirects === 0 || !in_array($status, self::REDIRECT_STATUSES, true) || $location === null) {
                break;
            }
            if ($hop >= $maxRedirects) {
                throw new ScraperException(sprintf('Too many redirects (limit %d).', $maxRedirects));
            }

            $next = UriResolver::resolve(new Uri($current), new Uri($location));
            if (!in_array(strtolower($next->getScheme()), $allowedRedirectSchemes, true) || $next->getHost() === '') {
                throw new ScraperException('Refusing to follow a redirect whose scheme or host is not allowed.');
            }
            // Both checks run before the redirect is followed.
            $nextUrl = UrlNormalizer::normalize((string) $next);
            HostPolicy::assert($nextUrl, $allowedHosts);
            if ($blockPrivate) {
                UrlGuard::assertPublic($nextUrl);
            }

            // 303 (and 301/302 after POST) switch to GET without a body, like browsers and Guzzle.
            if (!$strictRedirects && (($status === 303 && $method !== 'HEAD') || (($status === 301 || $status === 302) && $method === 'POST'))) {
                $method = 'GET';
                unset($request['json'], $request['form'], $request['body']);
                $request['headers'] = array_filter(
                    is_array($request['headers'] ?? null) ? $request['headers'] : [],
                    static fn (mixed $value, mixed $name): bool => !in_array(strtolower((string) $name), ['content-length', 'content-type', 'transfer-encoding'], true),
                    ARRAY_FILTER_USE_BOTH,
                );
            }
            if (!$sendReferer) {
                $request['headers'] = array_filter(
                    is_array($request['headers'] ?? null) ? $request['headers'] : [],
                    static fn (mixed $name): bool => strtolower((string) $name) !== 'referer',
                    ARRAY_FILTER_USE_KEY,
                );
            }
            if (!UrlOrigin::same($current, (string) $next)) {
                $request['headers'] = array_filter(
                    is_array($request['headers'] ?? null) ? $request['headers'] : [],
                    static fn (mixed $name): bool => !in_array(strtolower((string) $name), ['authorization', 'cookie', 'proxy-authorization', 'host', 'referer'], true),
                    ARRAY_FILTER_USE_KEY,
                );
            }
            $current = $nextUrl;
        }

        $body = $this->readBody($response, $maxBody, $url);
        $headers = [];
        $raw = self::invoke($response, 'headers');
        foreach (is_array($raw) ? $raw : [] as $name => $value) {
            if (is_string($name) && (is_string($value) || is_numeric($value))) {
                $headers[$name] = [(string) $value];
            }
        }

        return new FetchResponse($status, $headers, $body, $url, $current, (hrtime(true) - $startedAt) / 1_000_000);
    }

    /** @param array<string, mixed> $options */
    private function client(array $options): object
    {
        $proxyOption = $options['proxy'] ?? null;
        if (is_array($proxyOption)) {
            throw new InvalidArgumentException('RnetTransport accepts a single proxy URL; proxy arrays are not supported. Configure one proxy per transport or use ProxyTransport.');
        }
        if ($proxyOption !== null && !is_string($proxyOption)) {
            throw new InvalidArgumentException('RnetTransport proxy must be a URL string or null.');
        }
        $proxy = $proxyOption;
        $verify = $options['verify'] ?? true;
        if (!is_bool($verify)) {
            throw new InvalidArgumentException('RnetTransport supports boolean TLS verification only; custom CA paths are not supported.');
        }
        $cookies = ($options['cookies'] ?? null) instanceof CookieJarInterface ? spl_object_id($options['cookies']) : null;
        $config = [
            'timeout' => isset($options['timeout']) ? (float) $options['timeout'] : null,
            'connect_timeout' => isset($options['connect_timeout']) ? (float) $options['connect_timeout'] : null,
            'verify' => $verify,
            'proxy' => $proxy,
            'cookie_store' => $cookies !== null,
            'cookie_owner' => $cookies,
            'emulation' => $this->emulation,
        ];

        $key = hash('sha256', serialize($config));
        if (!isset($this->clients[$key])) {
            if (count($this->clients) >= 32) {
                $this->clients = [];
            }
            $this->clients[$key] = $this->clientFactory !== null ? ($this->clientFactory)($config) : self::buildClient($config);
        }

        return $this->clients[$key];
    }

    /** @param array<string, mixed> $config */
    private static function buildClient(array $config): object
    {
        if (!self::isAvailable()) {
            throw new RuntimeException('The php-rnet extension is not loaded. Download librnet.so for your PHP version from https://github.com/takielias/php-rnet/releases and enable it with extension=/path/to/librnet.so.');
        }

        // Reflection keeps this file free of static references to the optional extension's classes.
        $builder = (new \ReflectionClass(self::extensionClass('ClientBuilder')))->newInstance();
        // Redirects are handled by RnetTransport itself.
        self::invoke($builder, 'maxRedirects', 0);
        if (is_float($config['timeout'])) {
            self::invoke($builder, 'timeout', $config['timeout']);
        }
        if (is_float($config['connect_timeout'])) {
            self::invoke($builder, 'connectTimeout', $config['connect_timeout']);
        }
        if ($config['verify'] === false) {
            self::invoke($builder, 'verify', false);
        }
        if ($config['cookie_store'] === true) {
            self::invoke($builder, 'cookieStore', true);
        }
        if (is_string($config['emulation'])) {
            self::invoke($builder, 'impersonate', $config['emulation']);
        }
        if (is_string($config['proxy'])) {
            $proxy = (new \ReflectionClass(self::extensionClass('Proxy')))->getMethod('all')->invoke(null, $config['proxy']);
            self::invoke($builder, 'proxy', $proxy);
        }

        $client = self::invoke($builder, 'build');
        if (!is_object($client)) {
            throw new RuntimeException('php-rnet did not return a client.');
        }

        return $client;
    }

    private static function appendQuery(string $url, mixed $query): string
    {
        if ($query === null) {
            return $url;
        }
        if (!is_array($query) && !is_string($query)) {
            throw new InvalidArgumentException('RnetTransport query must be an array or query string.');
        }

        $queryString = is_array($query) ? http_build_query($query) : ltrim($query, '?');
        if ($queryString === '') {
            return $url;
        }

        $uri = new Uri($url);
        $existing = $uri->getQuery();

        return (string) $uri->withQuery($existing === '' ? $queryString : $existing . '&' . $queryString);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function requestOptions(array $options): array
    {
        $request = [];
        if (isset($options['headers']) && is_array($options['headers'])) {
            $headers = [];
            foreach ($options['headers'] as $name => $value) {
                if (is_string($name)) {
                    $headers[$name] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
                }
            }
            $request['headers'] = $headers;
        }
        // Query parameters are applied to the URL before the first request so string and array forms
        // behave consistently and fragments remain at the end of the URL.
        if (isset($options['json'])) {
            $request['json'] = $options['json'];
        } elseif (isset($options['form_params']) && is_array($options['form_params'])) {
            $request['form'] = array_map('strval', $options['form_params']);
        } elseif (isset($options['body'])) {
            $request['body'] = (string) $options['body'];
        }
        if (isset($options['timeout'])) {
            $request['timeout'] = (float) $options['timeout'];
        }

        return $request;
    }

    /** @param array<string, mixed> $request */
    private function call(object $client, string $method, string $url, array $request): object
    {
        try {
            $response = self::invoke($client, 'request', $method, $url, $request === [] ? null : $request);
        } catch (Throwable $exception) {
            throw self::translate($exception);
        }
        if (!is_object($response)) {
            throw new ScraperException('The php-rnet client returned an unexpected value.');
        }

        return $response;
    }

    private static function translate(Throwable $exception): Throwable
    {
        $class = $exception::class;
        if (str_starts_with($class, 'RNet\\')) {
            $short = substr($class, 5);
            // Messages can carry the full URL (with secrets); the original stays available as "previous".
            // A refused/unreachable proxy surfaces as a plain RequestException, so that class is treated as
            // transient too, except for errors in how the request itself was built.
            $isBuilderError = $short === 'RequestException' && stripos($exception->getMessage(), 'builder error') !== false;
            if (in_array($short, ['TimeoutException', 'ConnectionException', 'BodyException'], true) || ($short === 'RequestException' && !$isBuilderError)) {
                return new NetworkException('php-rnet ' . $short . ' (see previous exception).', 0, $exception);
            }

            return new ScraperException('php-rnet ' . $short . ' (see previous exception).', 0, $exception);
        }

        return $exception;
    }

    private function readBody(object $response, ?int $maxBody, string $url): string
    {
        $announced = $this->header($response, 'content-length');
        if ($maxBody !== null && $announced !== null && ctype_digit($announced) && (int) $announced > $maxBody) {
            throw ResponseTooLargeException::forLimit($maxBody, $url);
        }

        try {
            // text() is a plain string copy but throws on non-UTF-8 bodies (verified), so fall back to bytes().
            $text = self::invoke($response, 'text');
            $body = is_string($text) ? $text : null;
        } catch (Throwable) {
            $body = null;
        }

        if ($body === null) {
            try {
                $bytes = self::invoke($response, 'bytes');
            } catch (Throwable $exception) {
                throw self::translate($exception);
            }
            $body = '';
            foreach (array_chunk(is_array($bytes) ? $bytes : [], 8192) as $chunk) {
                $body .= pack('C*', ...$chunk);
            }
        }

        if ($maxBody !== null && strlen($body) > $maxBody) {
            throw ResponseTooLargeException::forLimit($maxBody, $url);
        }

        return $body;
    }

    private function header(object $response, string $name): ?string
    {
        $value = self::invoke($response, 'header', $name);

        return is_string($value) ? $value : null;
    }

    /**
     * Name of a class of the optional php-rnet extension. Built at runtime so static analysis does not
     * require the extension to be installed.
     *
     * @return class-string
     */
    private static function extensionClass(string $short): string
    {
        /** @var class-string $class */
        $class = 'RNet\\' . $short;

        return $class;
    }

    private static function invoke(object $target, string $method, mixed ...$arguments): mixed
    {
        /** @var callable $callable */
        $callable = [$target, $method];

        return $callable(...$arguments);
    }

    private static function resolveEmulation(?string $emulation, bool $hasFactory): ?string
    {
        if ($emulation === null || trim($emulation) === '') {
            return null;
        }
        $constant = 'RNet\Emulation::' . strtoupper(trim($emulation));
        if (defined($constant)) {
            $value = constant($constant);

            return is_string($value) ? $value : null;
        }
        if (!$hasFactory && class_exists('RNet\Emulation')) {
            throw new InvalidArgumentException(sprintf('Unknown php-rnet emulation "%s". Use an RNet\Emulation constant name such as CHROME_136.', $emulation));
        }

        return $emulation;
    }
}
