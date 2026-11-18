<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Contracts;

use HElturkey\WebTrawler\Browser\BrowserResult;

/**
 * Optional JavaScript-capable browser driver. No browser package is required by the HTTP-only core.
 * Drivers must enforce policy for every browser subrequest; the core can validate only the initial and final URL.
 * For untrusted targets, use browser-level request interception plus network egress isolation.
 */
interface BrowserDriver
{
    /** @param array<string, mixed> $options */
    public function render(string $url, array $options = []): BrowserResult;
}
