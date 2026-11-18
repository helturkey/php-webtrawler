<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Policy;

use HElturkey\WebTrawler\Options\FetchOptions;

/** Maps generic fetch options to the browser driver's millisecond-based option contract. */
final class BrowserOptionsResolver
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function resolve(array $options, RequestPolicy $policy): array
    {
        $defaults = $policy->defaultOptions();
        $timeoutMs = $options['timeout'] ?? ($defaults->timeout * 1000);
        if (!is_int($timeoutMs) && !is_float($timeoutMs)) {
            throw new \InvalidArgumentException('The browser timeout must be a finite non-negative number of milliseconds.');
        }
        if (!is_finite((float) $timeoutMs) || $timeoutMs < 0) {
            throw new \InvalidArgumentException('The browser timeout must be a finite non-negative number of milliseconds.');
        }

        $blockPrivate = $options['block_private_networks'] ?? $defaults->blockPrivateNetworks;
        if (!is_bool($blockPrivate)) {
            throw new \InvalidArgumentException('The browser block_private_networks option must be a boolean.');
        }
        $verifyTls = $options['verify_tls'] ?? $defaults->verifyTls;
        if (!is_bool($verifyTls)) {
            throw new \InvalidArgumentException('The browser verify_tls option must be a boolean.');
        }

        $proxy = array_key_exists('proxy', $options) ? $options['proxy'] : $defaults->proxy;
        if ($proxy !== null && !is_string($proxy) && !is_array($proxy)) {
            throw new \InvalidArgumentException('The browser proxy option must be a URL string, a proxy configuration array, or null.');
        }

        $allowedHosts = $options['allowed_hosts'] ?? $defaults->allowedHosts;
        if (!is_array($allowedHosts) || !array_is_list($allowedHosts) || array_filter($allowedHosts, static fn (mixed $host): bool => is_string($host) && trim($host, ". \t") !== '') !== $allowedHosts) {
            throw new \InvalidArgumentException('The browser allowed_hosts option must be a list of non-empty host names.');
        }

        $maxBodyBytes = array_key_exists('max_body_bytes', $options) ? $options['max_body_bytes'] : $defaults->maxBodyBytes;
        if ($maxBodyBytes !== null && (!is_int($maxBodyBytes) || $maxBodyBytes < 1)) {
            throw new \InvalidArgumentException('The browser max_body_bytes option must be a positive integer or null.');
        }

        $allowRedirects = $options['allow_redirects'] ?? $defaults->allowRedirects;
        if (!is_bool($allowRedirects)) {
            throw new \InvalidArgumentException('Browser allow_redirects must be a boolean; browsers do not support Guzzle redirect options.');
        }
        if (!$allowRedirects) {
            throw new \LogicException('Browsers follow redirects internally; a no-redirect policy cannot be enforced safely by a browser driver.');
        }

        // Browser timeouts use milliseconds; convert to FetchOptions seconds before policy comparison.
        $requestOptions = new FetchOptions(
            timeout: (float) $timeoutMs / 1000,
            connectTimeout: $defaults->connectTimeout,
            proxy: $proxy,
            maxBodyBytes: $maxBodyBytes,
            verifyTls: $verifyTls,
            blockPrivateNetworks: $blockPrivate,
            allowedHosts: $allowedHosts,
        );

        if ($policy->enforcesDefaults() && $policy->configuredDefaults() !== null) {
            $requestOptions = $requestOptions->tightenedBy($policy->configuredDefaults());
        }

        return array_replace($options, [
            'timeout' => $requestOptions->timeout * 1000,
            'proxy' => $requestOptions->proxy,
            'max_body_bytes' => $requestOptions->maxBodyBytes,
            'block_private_networks' => $requestOptions->blockPrivateNetworks,
            'verify_tls' => $requestOptions->verifyTls,
            'allowed_hosts' => $requestOptions->allowedHosts,
            'enforce_proxy' => $policy->enforcesDefaults() && $policy->configuredDefaults()?->proxy !== null,
        ]);
    }

}
