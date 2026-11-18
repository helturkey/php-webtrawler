<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Transport\Drivers;

use HElturkey\WebTrawler\Response\FetchResponse;
use HElturkey\WebTrawler\Contracts\BrowserDriver;
use HElturkey\WebTrawler\Contracts\Http\TransportInterface;

/**
 * Lets any BrowserDriver act as an TransportInterface, so a browser-backed fetch works through Scraper::fetch(),
 * sessions and spiders (one request at a time) with no change to Fetcher or SpiderRunner. Driver packages
 * only implement BrowserDriver::render(); this adapter does the option mapping and the conversion to
 * FetchResponse (BrowserResult::toFetchResponse()).
 *
 * Browsers navigate with GET, so any other method is refused rather than silently downgraded.
 */
final class BrowserTransport implements TransportInterface
{
    /** @param array<string, mixed> $driverOptions Extra options always passed to the driver (wait strategy, viewport ...). */
    public function __construct(
        private readonly BrowserDriver $driver,
        private readonly array $driverOptions = [],
    ) {
    }

    public function send(string $method, string $url, array $options = []): FetchResponse
    {
        if (strtoupper($method) !== 'GET') {
            throw new \InvalidArgumentException(sprintf('BrowserTransport can only navigate with GET, not %s.', strtoupper($method)));
        }

        $driverOptions = $this->driverOptions;
        $timeout = $options['timeout'] ?? null;
        if (is_int($timeout) || is_float($timeout)) {
            // Fetch options use seconds, browser drivers use milliseconds.
            $driverOptions['timeout'] = (float) $timeout * 1000;
        }
        foreach (['proxy', 'max_body_bytes', 'block_private_networks', 'allowed_hosts'] as $name) {
            if (array_key_exists($name, $options)) {
                $driverOptions[$name] = $options[$name];
            }
        }
        if (array_key_exists('verify', $options)) {
            $driverOptions['verify_tls'] = $options['verify'] !== false;
        }
        if (is_array($options['browser'] ?? null)) {
            $driverOptions = array_replace($driverOptions, $options['browser']);
        }

        return $this->driver->render($url, $driverOptions)->toFetchResponse();
    }
}
