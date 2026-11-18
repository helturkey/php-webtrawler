<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Transport\Drivers;

use HElturkey\WebTrawler\Policy\HostPolicy;
use HElturkey\WebTrawler\Policy\UrlGuard;
use HElturkey\WebTrawler\Policy\UrlNormalizer;
use HElturkey\WebTrawler\Proxy\ProxyTransport;
use HElturkey\WebTrawler\Response\FetchResponse;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Tunnel\Http1TunnelConnector;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\TimeoutCancellation;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\UriInterface;
use HElturkey\WebTrawler\Concurrency\Pending;
use HElturkey\WebTrawler\Contracts\Http\CancellableTransportInterface;
use HElturkey\WebTrawler\Exception\NetworkException;
use HElturkey\WebTrawler\Exception\RequestCancelledException;
use HElturkey\WebTrawler\Exception\ResponseTooLargeException;
use HElturkey\WebTrawler\Exception\ScraperException;
use HElturkey\WebTrawler\Support\UrlSanitizer;
use HElturkey\WebTrawler\Version;
use InvalidArgumentException;
use Throwable;

use function Amp\async;
use function Amp\Future\awaitFirst;

/**
 * Concurrent transport on Amp (amphp/http-client 5) and the Revolt event loop.
 *
 * Every request runs in its own fiber; tick() runs the Revolt loop until one of them finishes, so requests
 * progress while the spider runner waits in tick() (the loop is not running while pages are being parsed).
 * Redirects are followed here, not by the client, so every hop is checked against the host allow-list and the
 * private-network guard and credentials are dropped on cross-origin hops.
 *
 * Proxies: with amphp/http-tunnel installed the "proxy" option (an http:// proxy URL, as set by ProxyTransport)
 * is honoured by building one client per proxy. A custom $clientFactory is considered proxy-capable only
 * when the caller explicitly declares that capability; supportsProxy() reports that contract.
 * Inject your own HttpClient (or a $clientFactory) to control the connection pool, DNS, TLS or interceptors;
 * give it no redirect interceptor, because redirects are handled by this class.
 */
final class AmpTransport implements CancellableTransportInterface
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    private ?HttpClient $client;

    /** @var array<string, HttpClient> */
    private array $clients = [];

    /** @var array<int, array{future: Future<void>, pending: Pending, cancellation: DeferredCancellation}> */
    private array $inflight = [];
    private int $nextId = 1;

    /**
     * @param (\Closure(bool, string|null): HttpClient)|null $clientFactory Builds the client for (verifyTls, proxyUrl);
     *        used for every request that needs a non-default client (verification off, or a proxy).
     * @param bool $clientFactorySupportsProxy Set true only if the factory actually configures proxy tunnelling.
     */
    public function __construct(
        ?HttpClient $client = null,
        private readonly ?\Closure $clientFactory = null,
        private readonly bool $clientFactorySupportsProxy = false,
    ) {
        if (!class_exists(HttpClientBuilder::class) || !class_exists(\Revolt\EventLoop::class)) {
            throw new \RuntimeException('AmpTransport needs amphp/http-client 5 and revolt/event-loop. Run: composer require amphp/http-client');
        }
        $this->client = $client;
    }

    public function supportsAsync(): bool
    {
        return true;
    }

    /** Whether requests that carry a proxy can be served (needs amphp/http-tunnel or a $clientFactory). */
    public function supportsProxy(): bool
    {
        if ($this->clientFactory !== null) {
            return $this->clientFactorySupportsProxy;
        }

        return class_exists(Http1TunnelConnector::class);
    }

    public function send(string $method, string $url, array $options = []): FetchResponse
    {
        $pending = $this->sendAsync($method, $url, $options);
        while (!$pending->isSettled()) {
            $this->tick();
        }

        $response = $pending->value();
        if (!$response instanceof FetchResponse) {
            throw new \UnexpectedValueException('AmpTransport produced no FetchResponse.');
        }

        return $response;
    }

    public function sendAsync(string $method, string $url, array $options = []): Pending
    {
        $startedAt = hrtime(true);
        $url = UrlNormalizer::normalize($url);
        $config = $this->configure($url, $options);

        $pending = new Pending();
        $id = $this->nextId++;
        // async() starts the fiber on the next loop tick, so $this->inflight[$id] below is set before it runs.
        $cancellation = new DeferredCancellation();
        $future = async(function () use ($id, $pending, $cancellation, $method, $url, $options, $config, $startedAt): void {
            try {
                $pending->resolve($this->execute($method, $url, $options, $config, $startedAt, $cancellation));
            } catch (Throwable $exception) {
                $pending->reject($this->translate($exception, $config['timeout']));
            } finally {
                unset($this->inflight[$id]);
            }
        });
        $this->inflight[$id] = ['future' => $future, 'pending' => $pending, 'cancellation' => $cancellation];

        return $pending;
    }

    public function tick(?float $timeoutSeconds = null): void
    {
        if ($this->inflight === []) {
            return;
        }

        try {
            // The wrapper fibers never throw: failures are delivered through their Pending objects.
            awaitFirst(array_column($this->inflight, 'future'), $timeoutSeconds !== null ? new TimeoutCancellation(max(0.0, $timeoutSeconds)) : null);
        } catch (CancelledException) {
            // The tick timeout elapsed without a request finishing; the caller decides what to do next.
        }
    }

    public function cancelAll(): void
    {
        $open = $this->inflight;
        $this->inflight = [];
        foreach ($open as $entry) {
            // Reject first so the cancellation is not reported as a timeout.
            $entry['pending']->reject(new RequestCancelledException('The request was cancelled.'));
            $entry['cancellation']->cancel();
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array{timeout: float, connectTimeout: float, maxBodyBytes: int|null, blockPrivate: bool, allowedHosts: list<string>, verify: bool, proxy: string|null, follow: bool, maxRedirects: int, protocols: list<string>}
     */
    private function configure(string $url, array $options): array
    {
        $numbers = [];
        foreach (['timeout', 'connect_timeout'] as $name) {
            $value = $options[$name] ?? 0;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
                throw new InvalidArgumentException(sprintf('AmpTransport %s must be a finite non-negative number.', $name));
            }
            $numbers[$name] = (float) $value;
        }

        $maxBodyBytes = $options['max_body_bytes'] ?? null;
        if ($maxBodyBytes !== null && (!is_int($maxBodyBytes) || $maxBodyBytes < 1)) {
            throw new InvalidArgumentException('AmpTransport max_body_bytes must be a positive integer or null.');
        }
        $blockPrivate = $options['block_private_networks'] ?? false;
        if (!is_bool($blockPrivate)) {
            throw new InvalidArgumentException('AmpTransport block_private_networks must be a boolean.');
        }
        $allowedHosts = $options['allowed_hosts'] ?? [];
        if (!is_array($allowedHosts) || !array_is_list($allowedHosts)) {
            throw new InvalidArgumentException('AmpTransport allowed_hosts must be a list of non-empty host names.');
        }
        foreach ($allowedHosts as $host) {
            if (!is_string($host) || trim($host, ". \t") === '') {
                throw new InvalidArgumentException('AmpTransport allowed_hosts must be a list of non-empty host names.');
            }
        }
        $proxy = $options['proxy'] ?? null;
        if ($proxy !== null) {
            if (!is_string($proxy) || trim($proxy) === '') {
                throw new InvalidArgumentException('AmpTransport supports only proxy URLs given as a string.');
            }
            if (!$this->supportsProxy()) {
                throw new InvalidArgumentException('AmpTransport cannot use a proxy without amphp/http-tunnel (composer require amphp/http-tunnel) or a client factory.');
            }
        }

        $redirects = $options['allow_redirects'] ?? false;
        if (!is_bool($redirects) && !is_array($redirects)) {
            throw new InvalidArgumentException('AmpTransport allow_redirects must be a boolean or an options array.');
        }
        $follow = $redirects !== false;
        $maxRedirects = is_array($redirects) && is_int($redirects['max'] ?? null) ? $redirects['max'] : 10;
        $protocols = ['http', 'https'];
        if (is_array($redirects) && isset($redirects['protocols'])) {
            $declared = $redirects['protocols'];
            if (!is_array($declared) || !array_is_list($declared) || $declared === []) {
                throw new InvalidArgumentException('AmpTransport allow_redirects.protocols must be a non-empty list containing only http and https.');
            }
            $protocols = [];
            foreach ($declared as $protocol) {
                if (!is_string($protocol) || !in_array(strtolower($protocol), ['http', 'https'], true)) {
                    throw new InvalidArgumentException('AmpTransport allow_redirects.protocols must be a non-empty list containing only http and https.');
                }
                $protocols[] = strtolower($protocol);
            }
        }

        // The transport is public and may be called without Downloader; enforce the initial URL here too.
        HostPolicy::assert($url, $allowedHosts);
        if ($blockPrivate) {
            UrlGuard::assertPublic($url);
        }

        return [
            'timeout' => $numbers['timeout'],
            'connectTimeout' => $numbers['connect_timeout'],
            'maxBodyBytes' => $maxBodyBytes,
            'blockPrivate' => $blockPrivate,
            'allowedHosts' => $allowedHosts,
            'verify' => ($options['verify'] ?? true) !== false,
            'proxy' => $proxy,
            'follow' => $follow,
            'maxRedirects' => max(0, $maxRedirects),
            'protocols' => $protocols,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param array{timeout: float, connectTimeout: float, maxBodyBytes: int|null, blockPrivate: bool, allowedHosts: list<string>, verify: bool, proxy: string|null, follow: bool, maxRedirects: int, protocols: list<string>} $config
     */
    private function execute(string $method, string $url, array $options, array $config, int|float $startedAt, DeferredCancellation $abort): FetchResponse
    {
        $client = $this->clientFor($config['verify'], $config['proxy']);
        $cancellation = $config['timeout'] > 0
            ? new CompositeCancellation($abort->getCancellation(), new TimeoutCancellation($config['timeout']))
            : $abort->getCancellation();
        $jar = ($options['cookies'] ?? null) instanceof CookieJarInterface ? $options['cookies'] : null;

        $method = strtoupper(trim($method));
        if ($method === '') {
            throw new InvalidArgumentException('The HTTP method must not be empty.');
        }

        $headers = $this->headers($options);
        [$body, $contentType] = $this->body($options);
        $current = $this->withQuery($url, $options['query'] ?? null);

        for ($hop = 0;; $hop++) {
            $request = new Request($current, $method);
            foreach ($headers as $name => $value) {
                if ($name === '') {
                    throw new InvalidArgumentException('HTTP header names must not be empty.');
                }

                $request->setHeader($name, $value);
            }
            if ($body !== null) {
                $request->setBody($body);
                if ($contentType !== null && !$this->hasHeader($headers, 'content-type')) {
                    $request->setHeader('Content-Type', $contentType);
                }
            }
            if ($config['timeout'] > 0) {
                $request->setTransferTimeout($config['timeout']);
                $request->setInactivityTimeout($config['timeout']);
            }
            if ($config['connectTimeout'] > 0) {
                $request->setTcpConnectTimeout($config['connectTimeout']);
                $request->setTlsHandshakeTimeout($config['connectTimeout']);
            }
            // Let readBody() enforce the configured limit so callers consistently receive
            // ResponseTooLargeException (including when Content-Length is absent or chunks overrun it).
            // Amp's parser-level limit can fail before readBody() gets control of the response stream.
            $request->setBodySizeLimit(PHP_INT_MAX);

            $cookieRequest = new PsrRequest($method, $current);
            if ($jar instanceof CookieJarInterface) {
                $cookie = $jar->withCookieHeader($cookieRequest)->getHeaderLine('Cookie');
                if ($cookie !== '') {
                    $request->setHeader('Cookie', $cookie);
                }
            }

            $response = $client->request($request, $cancellation);
            if ($jar instanceof CookieJarInterface) {
                $jar->extractCookies($cookieRequest, new PsrResponse($response->getStatus(), $response->getHeaders()));
            }

            $location = $response->getHeader('location');
            if ($config['follow'] && in_array($response->getStatus(), self::REDIRECT_STATUSES, true) && $location !== null && $location !== '') {
                $this->discard($response);
                if ($hop >= $config['maxRedirects']) {
                    throw new ScraperException(sprintf('Too many redirects (%d) while fetching %s.', $config['maxRedirects'], UrlSanitizer::sanitize($url)));
                }

                $next = UriResolver::resolve(new Uri($current), new Uri($location));
                if (!in_array(strtolower($next->getScheme()), $config['protocols'], true)) {
                    throw new ScraperException('Redirect to a disallowed protocol was refused.');
                }
                // Checked before the hop is followed, so a disallowed target is never contacted.
                HostPolicy::assert((string) $next, $config['allowedHosts']);
                if ($config['blockPrivate']) {
                    UrlGuard::assertPublic((string) $next);
                }
                if (!$this->sameOrigin(new Uri($current), $next)) {
                    $headers = array_filter($headers, static fn (string $name): bool => !in_array(strtolower($name), ['authorization', 'cookie', 'proxy-authorization'], true), ARRAY_FILTER_USE_KEY);
                }
                $status = $response->getStatus();
                if (($status === 303 && !in_array($method, ['GET', 'HEAD'], true)) || (in_array($status, [301, 302], true) && $method === 'POST')) {
                    $method = 'GET';
                    $body = null;
                    $contentType = null;
                }
                $current = (string) $next;

                continue;
            }

            $responseBody = $this->readBody($response, $config['maxBodyBytes'], $url, $cancellation);
            $responseHeaders = [];
            foreach ($response->getHeaders() as $name => $values) {
                $responseHeaders[(string) $name] = array_values($values);
            }

            return new FetchResponse(
                status: $response->getStatus(),
                headers: $responseHeaders,
                body: $responseBody,
                requestedUrl: $url,
                effectiveUrl: $current,
                durationMs: (hrtime(true) - $startedAt) / 1_000_000,
            );
        }
    }

    private function readBody(Response $response, ?int $limit, string $url, Cancellation $cancellation): string
    {
        $announced = $response->getHeader('content-length');
        if ($limit !== null && $announced !== null && ctype_digit($announced) && (int) $announced > $limit) {
            $this->discard($response);
            throw ResponseTooLargeException::forLimit($limit, $url);
        }

        $stream = $response->getBody();
        $body = '';
        while (($chunk = $stream->read($cancellation)) !== null) {
            if ($limit !== null && strlen($chunk) > $limit - strlen($body)) {
                $this->discard($response);
                throw ResponseTooLargeException::forLimit($limit, $url);
            }
            $body .= $chunk;
        }

        return $body;
    }

    private function discard(Response $response): void
    {
        try {
            $response->getBody()->close();
        } catch (Throwable) {
            // Closing is best effort; the connection is dropped either way.
        }
    }

    private function translate(Throwable $exception, float $timeout): Throwable
    {
        if ($exception instanceof ScraperException || $exception instanceof InvalidArgumentException) {
            return $exception;
        }
        if ($exception instanceof CancelledException) {
            return new NetworkException(sprintf('Request timed out after %s seconds.', rtrim(rtrim(number_format($timeout, 3, '.', ''), '0'), '.')), 0, $exception);
        }
        if (str_starts_with($exception::class, 'Amp\\') || str_starts_with($exception::class, 'Revolt\\')) {
            return new NetworkException(UrlSanitizer::sanitizeText($exception->getMessage()), 0, $exception);
        }

        return $exception;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string|list<string>>
     */
    private function headers(array $options): array
    {
        $headers = [];
        $declared = $options['headers'] ?? [];
        if (is_array($declared)) {
            foreach ($declared as $name => $value) {
                if (is_string($name) && (is_string($value) || is_array($value))) {
                    /** @var string|list<string> $value */
                    $headers[$name] = $value;
                }
            }
        }
        if (!$this->hasHeader($headers, 'user-agent')) {
            $headers['User-Agent'] = Version::userAgent();
        }

        return $headers;
    }

    /** @param array<string, mixed> $headers */
    private function hasHeader(array $headers, string $lowerName): bool
    {
        foreach ($headers as $name => $_) {
            if (strtolower((string) $name) === $lowerName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: string|null, 1: string|null} body and its default content type
     */
    private function body(array $options): array
    {
        if (isset($options['form_params']) && is_array($options['form_params'])) {
            return [http_build_query($options['form_params'], '', '&', PHP_QUERY_RFC1738), 'application/x-www-form-urlencoded'];
        }
        if (array_key_exists('json', $options)) {
            return [json_encode($options['json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json'];
        }
        if (isset($options['body']) && is_string($options['body'])) {
            return [$options['body'], null];
        }

        return [null, null];
    }

    private function withQuery(string $url, mixed $query): string
    {
        if ($query === null || $query === [] || $query === '') {
            return $url;
        }
        $extra = is_array($query) ? http_build_query($query, '', '&', PHP_QUERY_RFC3986) : (string) $query;
        $uri = new Uri($url);
        $existing = $uri->getQuery();

        return (string) $uri->withQuery($existing === '' ? $extra : $existing . '&' . $extra);
    }

    private function sameOrigin(UriInterface $a, UriInterface $b): bool
    {
        return strtolower($a->getScheme()) === strtolower($b->getScheme())
            && strtolower($a->getHost()) === strtolower($b->getHost())
            && ($a->getPort() ?? ($a->getScheme() === 'https' ? 443 : 80)) === ($b->getPort() ?? ($b->getScheme() === 'https' ? 443 : 80));
    }

    private function clientFor(bool $verify, ?string $proxy): HttpClient
    {
        if ($verify && $proxy === null && $this->client !== null) {
            return $this->client;
        }

        $key = ($verify ? 'verify' : 'insecure') . '|' . ($proxy ?? '');
        if (isset($this->clients[$key])) {
            return $this->clients[$key];
        }
        if ($this->clientFactory !== null && ($proxy === null || $this->clientFactorySupportsProxy)) {
            return $this->clients[$key] = ($this->clientFactory)($verify, $proxy);
        }

        $builder = self::builder();
        if ($verify && $proxy === null) {
            return $this->clients[$key] = $builder->build();
        }

        $connect = new ConnectContext();
        if (!$verify) {
            $connect = $connect->withTlsContext((new ClientTlsContext(''))->withoutPeerVerification());
        }
        $connector = null;
        if ($proxy !== null) {
            $address = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $proxy);
            $connector = new Http1TunnelConnector((string) $address);
        }
        $pool = new UnlimitedConnectionPool(new DefaultConnectionFactory($connector, $connect));

        return $this->clients[$key] = $builder->usingPool($pool)->build();
    }

    private static function builder(): HttpClientBuilder
    {
        // Redirects and retries are handled by this transport and the spider runner, never inside the client.
        return (new HttpClientBuilder())->followRedirects(0)->retry(0);
    }
}
