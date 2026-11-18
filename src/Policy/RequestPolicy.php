<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Policy;

use HElturkey\WebTrawler\Exception\ResponseTooLargeException;
use HElturkey\WebTrawler\Options\FetchOptions;
use HElturkey\WebTrawler\Response\FetchResponse;

/** Resolves per-request options and enforces URL/response security boundaries. */
final readonly class RequestPolicy
{
    public function __construct(
        private ?FetchOptions $defaults = null,
        private bool $enforceDefaults = false,
    ) {
    }

    public function configuredDefaults(): ?FetchOptions
    {
        return $this->defaults;
    }

    public function enforcesDefaults(): bool
    {
        return $this->enforceDefaults;
    }

    public function defaultOptions(): FetchOptions
    {
        return $this->defaults ?? new FetchOptions();
    }

    /**
     * With $enforceDefaults, the default options act as a security policy: whatever options a call (or a
     * spider request) supplies, private-network blocking, the size limit and the host allow-list can only
     * become stricter than the defaults, never looser.
     */
    public function resolveOptions(?FetchOptions $options): FetchOptions
    {
        if ($options === null) {
            return $this->defaultOptions();
        }

        return $this->enforceDefaults && $this->defaults !== null ? $options->tightenedBy($this->defaults) : $options;
    }

    public function prepareUrl(string $url, FetchOptions $options): string
    {
        $url = UrlNormalizer::normalize($url);
        HostPolicy::assert($url, $options->allowedHosts);
        if ($options->blockPrivateNetworks) {
            UrlGuard::assertPublic($url);
        }

        return $url;
    }

    public function verify(FetchResponse $response, string $url, FetchOptions $options): FetchResponse
    {
        if ($options->maxBodyBytes !== null && strlen($response->body) > $options->maxBodyBytes) {
            throw ResponseTooLargeException::forLimit($options->maxBodyBytes, $url);
        }
        if ($response->effectiveUrl !== $url) {
            HostPolicy::assert($response->effectiveUrl, $options->allowedHosts);
            if ($options->blockPrivateNetworks) {
                UrlGuard::assertPublic($response->effectiveUrl);
            }
        }

        return $response;
    }
}
