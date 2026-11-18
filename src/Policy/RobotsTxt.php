<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Policy;

/**
 * A parsed robots.txt file, evaluated the way RFC 9309 describes it.
 *
 * - Groups are chosen by the crawler's product token (case-insensitive); the "*" group is the fallback.
 * - The longest matching rule wins; on a tie "Allow" wins. "*" matches any run of characters and a trailing
 *   "$" anchors the end of the path. Matching is a linear glob, never a regular expression, so a hostile
 *   robots.txt cannot cause catastrophic backtracking.
 * - Input is capped (500 KiB, like the RFC's minimum) and so is the number of rules.
 */
final class RobotsTxt
{
    public const MAX_BYTES = 512_000;
    private const MAX_RULES = 10_000;
    private const MAX_PATTERN_LENGTH = 2_048;

    /**
     * @param array<string, list<array{allow: bool, pattern: string}>> $rules Rules per user-agent token; a token
     *        with an empty list is a group that allows everything (and so replaces the "*" group).
     * @param array<string, float> $delays Crawl-delay seconds per user-agent token
     * @param list<string> $sitemaps
     */
    private function __construct(
        private readonly array $rules,
        private readonly array $delays,
        private readonly array $sitemaps,
        private readonly bool $disallowAll = false,
    ) {
    }

    public static function allowAll(): self
    {
        return new self([], [], []);
    }

    /** What a crawler must assume when robots.txt cannot be fetched because the server failed (RFC 9309 2.3.1.4). */
    public static function disallowAll(): self
    {
        return new self([], [], [], true);
    }

    public static function parse(string $content): self
    {
        if (strlen($content) > self::MAX_BYTES) {
            $content = substr($content, 0, self::MAX_BYTES);
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        /** @var array<string, list<array{allow: bool, pattern: string}>> $rules */
        $rules = [];
        /** @var array<string, float> $delays */
        $delays = [];
        $sitemaps = [];
        $currentAgents = [];
        $collectingAgents = true;
        $ruleCount = 0;

        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            $hash = strpos($line, '#');
            if ($hash !== false) {
                $line = substr($line, 0, $hash);
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $field = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            if ($field === 'user-agent') {
                if (!$collectingAgents) {
                    $currentAgents = [];
                    $collectingAgents = true;
                }
                $agent = strtolower($value);
                if ($agent !== '') {
                    $currentAgents[] = $agent;
                    $rules[$agent] ??= [];
                }
                continue;
            }

            if ($field === 'sitemap') {
                if ($value !== '' && count($sitemaps) < 100) {
                    $sitemaps[] = $value;
                }
                continue;
            }

            if (!in_array($field, ['allow', 'disallow', 'crawl-delay'], true)) {
                continue;
            }
            $collectingAgents = false;

            foreach ($currentAgents as $agent) {
                if ($field === 'crawl-delay') {
                    if (is_numeric($value) && (float) $value >= 0 && (float) $value <= 3600) {
                        $delays[$agent] = (float) $value;
                    }
                    continue;
                }
                // "Disallow:" with an empty value allows everything; an empty "Allow:" does nothing.
                if ($value === '' || strlen($value) > self::MAX_PATTERN_LENGTH || ++$ruleCount > self::MAX_RULES) {
                    continue;
                }
                $rules[$agent][] = ['allow' => $field === 'allow', 'pattern' => self::normalizePath($value)];
            }
        }

        return new self($rules, $delays, $sitemaps);
    }

    public function isAllowed(string $userAgent, string $pathAndQuery): bool
    {
        if ($this->disallowAll) {
            return false;
        }
        $path = self::normalizePath($pathAndQuery === '' ? '/' : $pathAndQuery);
        if ($path === '/robots.txt') {
            return true;
        }

        $token = $this->groupToken($userAgent);
        if ($token === null) {
            return true;
        }

        $bestLength = -1;
        $allowed = true;
        foreach ($this->rules[$token] as $rule) {
            $length = self::matchLength($rule['pattern'], $path);
            if ($length === null) {
                continue;
            }
            if ($length > $bestLength || ($length === $bestLength && $rule['allow'])) {
                $bestLength = $length;
                $allowed = $rule['allow'];
            }
        }

        return $allowed;
    }

    public function isUrlAllowed(string $userAgent, string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return $this->isAllowed($userAgent, ($path === '' ? '/' : $path) . (is_string($query) && $query !== '' ? '?' . $query : ''));
    }

    public function crawlDelaySeconds(string $userAgent): ?float
    {
        $token = $this->groupToken($userAgent);

        return $token === null ? null : ($this->delays[$token] ?? null);
    }

    /** @return list<string> */
    public function sitemaps(): array
    {
        return $this->sitemaps;
    }

    /** The product token of a User-Agent string: "WebTrawler/1.2 (+https://x)" becomes "webtrawler". */
    public static function productToken(string $userAgent): string
    {
        $token = preg_split('/[\/\s;(:]/', trim($userAgent), 2)[0] ?? '';

        return strtolower($token === '' ? '*' : $token);
    }

    /** The user-agent token whose group applies to this crawler: its own group, else "*", else none. */
    private function groupToken(string $userAgent): ?string
    {
        $token = self::productToken($userAgent);
        if (array_key_exists($token, $this->rules)) {
            return $token;
        }

        return array_key_exists('*', $this->rules) ? '*' : null;
    }

    /** Length of the pattern when it matches the path (the "specificity" of the rule), null otherwise. */
    private static function matchLength(string $pattern, string $path): ?int
    {
        $anchored = str_ends_with($pattern, '$');
        $glob = $anchored ? substr($pattern, 0, -1) : $pattern;

        return self::globMatches($glob, $path, $anchored) ? strlen($pattern) : null;
    }

    /** Linear-time wildcard match: "*" is any run of characters; unanchored patterns match any prefix. */
    private static function globMatches(string $pattern, string $path, bool $anchored): bool
    {
        $patternLength = strlen($pattern);
        $pathLength = strlen($path);
        $p = 0;
        $s = 0;
        $starPattern = -1;
        $starPath = 0;

        while ($s < $pathLength) {
            if ($p < $patternLength && $pattern[$p] === '*') {
                $starPattern = $p++;
                $starPath = $s;
                continue;
            }
            if ($p >= $patternLength) {
                if (!$anchored) {
                    return true;
                }
            } elseif ($pattern[$p] === $path[$s]) {
                $p++;
                $s++;
                continue;
            }
            if ($starPattern >= 0) {
                $p = $starPattern + 1;
                $s = ++$starPath;
                continue;
            }

            return false;
        }
        while ($p < $patternLength && $pattern[$p] === '*') {
            $p++;
        }

        return $p >= $patternLength;
    }

    /**
     * Compare paths the way servers do: percent-escapes use upper-case hex, and escaped unreserved characters
     * ("%7Euser" and "~user") are the same path.
     */
    private static function normalizePath(string $path): string
    {
        return (string) preg_replace_callback(
            '/%([0-9A-Fa-f]{2})/',
            static function (array $match): string {
                $char = chr(((int) hexdec($match[1])) & 0xFF);

                return preg_match('/^[A-Za-z0-9\-._~]$/', $char) === 1 ? $char : '%' . strtoupper($match[1]);
            },
            $path,
        );
    }
}
