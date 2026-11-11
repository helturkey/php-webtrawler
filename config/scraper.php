<?php

declare(strict_types=1);

return [
    /*
     * HTTP backend: "guzzle" (default, supports concurrent crawling), "rnet"
     * (php-rnet extension: browser TLS/HTTP2 fingerprints, blocking only), or "impity"
     * (optional curl-impersonate transport; requires dekor/php-impity and its matching binary).
     */
    'transport' => env('SCRAPER_TRANSPORT', 'guzzle'),

    // php-rnet browser profile, an RNet\Emulation constant name such as CHROME_136.
    'emulation' => env('SCRAPER_EMULATION'),

    'timeout' => (float) env('SCRAPER_TIMEOUT', 30),
    'connect_timeout' => (float) env('SCRAPER_CONNECT_TIMEOUT', 10),

    // Responses larger than this are aborted. Use null to disable (not recommended).
    'max_body_bytes' => env('SCRAPER_MAX_BODY_BYTES', 20 * 1024 * 1024),

    /*
     * Refuse loopback/private/link-local targets, including redirects. Keep this on whenever URLs can come
     * from users; it does not replace network-level isolation against DNS rebinding.
     */
    'block_private_networks' => (bool) env('SCRAPER_BLOCK_PRIVATE_NETWORKS', true),

    // Optional exact-host/subdomain allow-list. Empty means no host restriction.
    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('SCRAPER_ALLOWED_HOSTS', ''))))),

    // Laravel config is treated as a hard security policy by default. Per-request options may narrow it,
    // but cannot disable private-network blocking, raise the size limit or expand the host allow-list.
    'enforce_default_security' => (bool) env('SCRAPER_ENFORCE_DEFAULT_SECURITY', true),

    // Retry 429/5xx and network errors for GET/HEAD. Set max_attempts to 1 to disable.
    'retries' => [
        'max_attempts' => (int) env('SCRAPER_RETRY_ATTEMPTS', 3),
        'base_delay_ms' => 500,
        'max_delay_ms' => 30000,
    ],

    // Rotating proxy pool, e.g. ['http://user:pass@proxy1:8080', 'socks5h://proxy2:1080'].
    // Laravel enforces this pool for HTTP fetches and spider requests; Playwright rendering uses its own browser proxy setting and is not routed through this pool.
    'proxies' => array_values(array_filter(explode(',', (string) env('SCRAPER_PROXIES', '')))),

    // Where adaptive-selector snapshots live: "file" or "memory".
    'snapshots' => [
        'driver' => env('SCRAPER_SNAPSHOTS', 'file'),
        'path' => storage_path('app/scraper/snapshots.json'),
    ],
];
