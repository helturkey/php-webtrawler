<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Response;

/**
 * Recognises responses that are anti-bot challenges, CAPTCHAs, WAF denials or rate limits instead of content.
 *
 * It only *detects*: it never solves a challenge. Knowing a response is a block lets a crawl count it, skip
 * parsing it (a challenge page would otherwise yield empty or wrong items), slow down, or escalate the URL to a
 * different session such as a real browser. Markers are matched on the first 100 KB of the body. A page
 * that merely embeds a CAPTCHA widget and answers 200 is not reported unless it is also tiny and
 * challenge-shaped, so login forms are not mistaken for blocks.
 */
final readonly class BlockDetector
{
    private const BODY_WINDOW = 100_000;
    private const SMALL_PAGE = 30_000;

    /** @var array<string, list<string>> vendor => lowercase body markers */
    private const MARKERS = [
        'cloudflare' => ['just a moment...', '_cf_chl_opt', 'cf-chl-', 'challenge-platform', 'attention required! | cloudflare', 'cf-error-details', 'cloudflare ray id'],
        'akamai' => ['errors.edgesuite.net', 'reference #18.', 'akamai ghost'],
        'datadome' => ['geo.captcha-delivery.com', 'datadome'],
        'perimeterx' => ['px-captcha', '_pxhd', 'perimeterx'],
        'imperva' => ['incapsula incident id', '_incapsula_resource', 'request unsuccessful. incapsula'],
        'captcha' => ['g-recaptcha', 'h-captcha', 'hcaptcha.com', 'cf-turnstile', 'verify you are human', 'are you a robot', 'unusual traffic from your computer'],
    ];

    /** @var list<string> The only markers trusted on a 2xx page: they appear on the interstitial itself, not on pages that merely run Cloudflare. */
    private const INTERSTITIAL_MARKERS = ['just a moment...', '_cf_chl_opt'];

    /**
     * @param bool $treatForbiddenAsBlocked Report every 403 without a known marker as a generic block.
     */
    public function __construct(private bool $treatForbiddenAsBlocked = false)
    {
    }

    public static function default(): self
    {
        return new self();
    }

    public function inspect(FetchResponse $response): ?BlockSignal
    {
        $status = $response->status;

        if (strtolower((string) $response->header('cf-mitigated')) === 'challenge') {
            return new BlockSignal('cloudflare', 'Cloudflare challenge (cf-mitigated header)', $status);
        }
        if ($status === 429) {
            return new BlockSignal('rate-limit', 'HTTP 429 Too Many Requests', $status);
        }

        $suspicious = in_array($status, [401, 403, 406, 503], true);
        $isSmall = strlen($response->body) < self::SMALL_PAGE;
        if (!$suspicious && !($status >= 200 && $status < 300 && $isSmall)) {
            return null;
        }

        $body = strtolower(substr($response->body, 0, self::BODY_WINDOW));
        if (!$suspicious) {
            foreach (self::INTERSTITIAL_MARKERS as $marker) {
                if (str_contains($body, $marker)) {
                    return new BlockSignal('cloudflare', 'Cloudflare interstitial page served with a 2xx status', $status);
                }
            }

            return null;
        }

        foreach (self::MARKERS as $vendor => $markers) {
            foreach ($markers as $marker) {
                if (str_contains($body, $marker)) {
                    return new BlockSignal($vendor, sprintf('Response matched a %s challenge marker', $vendor), $status);
                }
            }
        }

        if ($this->treatForbiddenAsBlocked && $status === 403) {
            return new BlockSignal('generic', 'HTTP 403 Forbidden', $status);
        }

        return null;
    }
}
