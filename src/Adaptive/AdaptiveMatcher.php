<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Adaptive;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use HElturkey\WebTrawler\Parsing\Element;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Support\Text;
use HElturkey\WebTrawler\Support\UrlSanitizer;

final class AdaptiveMatcher
{
    /** @var list<string> */
    private const TRACKING_PARAMETERS = [
        'fbclid', 'gclid', 'dclid', 'msclkid', 'mc_cid', 'mc_eid',
        'token', 'access_token', 'refresh_token', 'api_key', 'apikey', 'key',
        'secret', 'signature', 'auth', 'authorization', 'session', 'session_id',
        'csrf', 'code', 'password',
    ];

    /**
     * Per-match memo of structural facts keyed by DOM node id. The node is kept in
     * the entry so an id can never be recycled while the cache is alive.
     *
     * @var array<int, array{node: \DOMElement, tag: string, id: string, classes: list<string>, position: int}>
     */
    private array $factCache = [];

    /**
     * @param int|null $maxCandidateTextChars null (default) compares the full visible text of every candidate, which
     *        reproduces the 0.3.x scores exactly. A positive value compares only the first N characters of long
     *        candidates (e.g. page-level containers): faster and lighter on huge pages, but it removes the length
     *        penalty of very long text, so scores of long candidates rise slightly (measured: about +0.02 at
     *        10 000 characters, +0.03 at 50 000). Results for candidates at or below N are unchanged.
     */
    public function __construct(
        private readonly int $maxCandidates = 5000,
        private readonly AdaptiveScoringProfile $profile = new AdaptiveScoringProfile(),
        private readonly ?int $maxCandidateTextChars = null,
    ) {
        if ($maxCandidates < 1) {
            throw new \InvalidArgumentException('The maximum candidate count must be positive.');
        }
        if ($maxCandidateTextChars !== null && $maxCandidateTextChars < 500) {
            throw new \InvalidArgumentException('The candidate text limit must be null or at least 500 characters (snapshots keep up to 500).');
        }
    }

    public function match(
        Page $page,
        ElementSnapshot $snapshot,
        float $minimumConfidence = 0.52,
        float $minimumMargin = 0.045,
        int $resultLimit = 5,
    ): AdaptiveResult {
        $this->validateThresholds($minimumConfidence, $minimumMargin, $resultLimit);

        $savedAuthority = $this->urlAuthority($snapshot->sourceUrl);
        $currentAuthority = $this->urlAuthority($page->url());
        if ($savedAuthority !== '' && $currentAuthority !== '' && $savedAuthority !== $currentAuthority) {
            return new AdaptiveResult(
                null,
                [],
                false,
                'Saved snapshot belongs to a different host.',
                'host_mismatch',
            );
        }

        if (!$this->hasIdentifyingSignals($snapshot)) {
            return new AdaptiveResult(
                null,
                [],
                false,
                'The saved snapshot has no identifying attributes, stable classes, accessible name, or text; tag-only recovery is unsafe.',
                'insufficient_snapshot',
            );
        }

        // Read one extra element to detect a partial scan. A result from a truncated
        // candidate set must never be accepted because a better match may be unseen.
        $elements = $page->allElements($this->maxCandidates + 1);
        $truncated = count($elements) > $this->maxCandidates;
        if ($truncated) {
            $elements = array_slice($elements, 0, $this->maxCandidates);
        }

        $matches = [];
        $this->factCache = [];
        try {
            foreach ($elements as $candidate) {
                [$score, $signals, $reasons, $contributions] = $this->score($snapshot, $candidate);
                if ($score <= 0.0) {
                    continue;
                }

                $matches[] = new AdaptiveMatch($candidate, $score, $signals, $reasons, $contributions);
            }
        } finally {
            $this->factCache = [];
        }

        usort($matches, static fn (AdaptiveMatch $a, AdaptiveMatch $b): int => $b->confidence <=> $a->confidence);

        // The runner-up is retained for ambiguity checks even when resultLimit is 1.
        $best = $matches[0] ?? null;
        $second = $matches[1] ?? null;
        $margin = $best !== null && $second !== null
            ? max(0.0, $best->confidence - $second->confidence)
            : null;
        $visibleMatches = array_slice($matches, 0, $resultLimit);

        if ($truncated) {
            return new AdaptiveResult(
                $best,
                $visibleMatches,
                false,
                sprintf(
                    'Candidate scan limit reached (%d). The result is incomplete, so adaptive recovery was not accepted.',
                    $this->maxCandidates,
                ),
                'candidate_limit',
                count($elements),
                true,
                $margin,
            );
        }

        if ($best === null) {
            return new AdaptiveResult(
                null,
                [],
                false,
                'No candidate elements were found.',
                'no_match',
                count($elements),
            );
        }

        if ($best->confidence < $minimumConfidence) {
            return new AdaptiveResult(
                $best,
                $visibleMatches,
                false,
                sprintf(
                    'Best candidate confidence %.3f is below the required %.3f.',
                    $best->confidence,
                    $minimumConfidence,
                ),
                'low_confidence',
                count($elements),
                false,
                $margin,
            );
        }

        if ($second !== null && ($margin ?? 0.0) < $minimumMargin) {
            return new AdaptiveResult(
                $best,
                $visibleMatches,
                false,
                sprintf(
                    'The top candidates are ambiguous (margin %.3f; required %.3f).',
                    $margin,
                    $minimumMargin,
                ),
                'ambiguous',
                count($elements),
                false,
                $margin,
            );
        }

        return new AdaptiveResult(
            $best,
            $visibleMatches,
            true,
            null,
            'accepted',
            count($elements),
            false,
            $margin,
        );
    }

    public function profile(): AdaptiveScoringProfile
    {
        return $this->profile;
    }

    private function urlAuthority(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '';
        }

        $host = strtolower((string) $parts['host']);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = $parts['port'] ?? match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };

        return $host . ($port !== null ? ':' . $port : '');
    }

    private function validateThresholds(float $minimumConfidence, float $minimumMargin, int $resultLimit): void
    {
        if (!is_finite($minimumConfidence) || !is_finite($minimumMargin)
            || $minimumConfidence < 0 || $minimumConfidence > 1
            || $minimumMargin < 0 || $minimumMargin > 1) {
            throw new \InvalidArgumentException('Confidence and margin thresholds must be finite numbers between 0 and 1.');
        }
        if ($resultLimit < 1) {
            throw new \InvalidArgumentException('The adaptive result limit must be positive.');
        }
    }

    private function hasIdentifyingSignals(ElementSnapshot $snapshot): bool
    {
        return $snapshot->attributes !== []
            || $snapshot->classes !== []
            || $snapshot->text !== ''
            || $snapshot->ownText !== ''
            || $snapshot->accessibleName !== '';
    }

    /**
     * @return array{float, array<string, float>, list<string>, array<string, float>}
     */
    private function score(ElementSnapshot $snapshot, Element $candidate): array
    {
        $signals = [];
        $weights = [];
        $reasons = [];
        $candidateAttributes = $candidate->attributes();

        if ($snapshot->tag !== '') {
            $this->recordSignal('tag', $this->tagSimilarity($snapshot->tag, $candidate->tag()), $signals, $weights);
        }

        foreach ($snapshot->attributes as $name => $expected) {
            if ($expected === '') {
                continue;
            }

            $weight = $this->profile->weight($name);
            if ($weight <= 0.0) {
                continue;
            }

            $actual = $candidateAttributes[$name] ?? '';
            $similarity = $this->attributeSimilarity($name, $expected, $actual, $snapshot->sourceUrl, $candidate->baseUrl());
            $this->recordSignal($name, $similarity, $signals, $weights);
            if ($similarity >= 0.99) {
                $reasons[] = sprintf('Exact %s attribute match.', $name);
            } elseif ($similarity >= 0.75) {
                $reasons[] = sprintf('%s attribute is highly similar.', $name);
            }
        }

        if ($snapshot->classes !== []) {
            $similarity = $this->setSimilarity($snapshot->classes, $this->stableCandidateClasses($candidate->classTokens()));
            $this->recordSignal('classes', $similarity, $signals, $weights);
            if ($similarity >= 0.5) {
                $reasons[] = 'Stable CSS class tokens overlap.';
            }
        }

        if ($snapshot->text !== '') {
            $candidateText = $candidate->text();
            if ($this->maxCandidateTextChars !== null) {
                $candidateText = Text::truncateChars($candidateText, $this->maxCandidateTextChars);
            }
            $similarity = $this->textSimilarity($snapshot->text, $candidateText);
            $this->recordSignal('text', $similarity, $signals, $weights);
            if ($similarity >= 0.8) {
                $reasons[] = 'Visible text is highly similar.';
            }
        }

        if ($snapshot->ownText !== '' && $this->normalizeText($snapshot->ownText) !== $this->normalizeText($snapshot->text)) {
            $similarity = $this->textSimilarity($snapshot->ownText, $candidate->ownText());
            $this->recordSignal('own_text', $similarity, $signals, $weights);
            if ($similarity >= 0.8) {
                $reasons[] = 'Direct text nodes are highly similar.';
            }
        }

        $accessibleAttributeValues = array_intersect_key(
            $snapshot->attributes,
            array_flip(['aria-label', 'title', 'alt', 'placeholder']),
        );
        $accessibleNameDuplicatesAttribute = false;
        foreach ($accessibleAttributeValues as $value) {
            if ($this->normalizeText($value) === $this->normalizeText($snapshot->accessibleName)) {
                $accessibleNameDuplicatesAttribute = true;
                break;
            }
        }
        if ($snapshot->accessibleName !== '' && !$accessibleNameDuplicatesAttribute) {
            $similarity = $this->textSimilarity($snapshot->accessibleName, $candidate->accessibleName());
            $this->recordSignal('accessible_name', $similarity, $signals, $weights);
            if ($similarity >= 0.8) {
                $reasons[] = 'Accessible label is highly similar.';
            }
        }

        if ($snapshot->parent !== null) {
            $parent = $candidate->parent();
            $similarity = $parent === null ? 0.0 : $this->signatureSimilarity($snapshot->parent, $this->facts($parent));
            $this->recordSignal('parent', $similarity, $signals, $weights);
        }

        if ($snapshot->ancestors !== []) {
            $similarity = $this->ancestorSimilarity($snapshot->ancestors, array_map($this->facts(...), $candidate->ancestors(8)));
            $this->recordSignal('ancestors', $similarity, $signals, $weights);
        }

        if ($snapshot->siblings !== []) {
            $similarity = $this->siblingSimilarity($snapshot->siblings, $candidate->siblingContext(2));
            $this->recordSignal('siblings', $similarity, $signals, $weights);
        }

        if ($snapshot->sameTagPosition > 0) {
            $actualPosition = $candidate->siblingElementPosition();
            $difference = abs($snapshot->sameTagPosition - $actualPosition);
            $similarity = $actualPosition === 0 ? 0.0 : 1 / (1 + $difference);
            $this->recordSignal('position', $similarity, $signals, $weights);
        }

        $availableWeight = array_sum($weights);
        if ($availableWeight <= 0.0) {
            return [0.0, [], [], []];
        }

        $weightedScore = 0.0;
        $contributions = [];
        foreach ($signals as $name => $similarity) {
            $contribution = ($similarity * $weights[$name]) / $availableWeight;
            $contributions[$name] = $contribution;
            $weightedScore += $contribution;
        }

        arsort($contributions);
        if (($signals['tag'] ?? 0.0) === 1.0) {
            $reasons[] = 'Element tag matches.';
        }

        return [
            min(1.0, max(0.0, $weightedScore)),
            $signals,
            array_values(array_unique($reasons)),
            $contributions,
        ];
    }

    /**
     * @param array<string, float> $signals
     * @param array<string, float> $weights
     */
    private function recordSignal(string $name, float $similarity, array &$signals, array &$weights): void
    {
        $weight = $this->profile->weight($name);
        if ($weight <= 0.0) {
            return;
        }

        $signals[$name] = min(1.0, max(0.0, $similarity));
        $weights[$name] = $weight;
    }

    private function tagSimilarity(string $expected, string $actual): float
    {
        if ($expected === $actual) {
            return 1.0;
        }

        $families = [
            'heading' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
            'text' => ['p', 'span', 'strong', 'em', 'small', 'label'],
            'container' => ['div', 'section', 'article', 'main', 'aside', 'header', 'footer'],
            'list-item' => ['li', 'dt', 'dd'],
        ];
        foreach ($families as $family) {
            if (in_array($expected, $family, true) && in_array($actual, $family, true)) {
                return 0.72;
            }
        }

        return 0.0;
    }

    private function attributeSimilarity(
        string $name,
        string $expected,
        string $actual,
        string $expectedBaseUrl,
        string $actualBaseUrl,
    ): float {
        if ($actual === '') {
            return 0.0;
        }

        if (in_array($name, ['href', 'src'], true)) {
            $expected = $this->normalizeUrlAttribute($expected, $expectedBaseUrl);
            $actual = $this->normalizeUrlAttribute($actual, $actualBaseUrl);
        } elseif (in_array($name, ['aria-labelledby', 'aria-describedby', 'aria-controls', 'rel'], true)) {
            return $this->setSimilarity($this->tokens($expected), $this->tokens($actual));
        }

        return $this->textSimilarity($expected, $actual);
    }

    private function normalizeUrlAttribute(string $value, string $baseUrl): string
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, 'data:') || str_starts_with($value, 'javascript:')) {
            return strtolower($value);
        }
        // Snapshots store sanitized URLs, so candidates are sanitized the same way before comparing.
        $value = UrlSanitizer::sanitize($value);

        try {
            $uri = new Uri($value);
            if ($baseUrl !== '') {
                $uri = UriResolver::resolve(new Uri($baseUrl), $uri);
            }

            $path = preg_replace('#/+#', '/', $uri->getPath()) ?? $uri->getPath();
            if ($path !== '/') {
                $path = rtrim($path, '/');
            }
            $query = $uri->getQuery();
            if ($query !== '') {
                parse_str($query, $parameters);
                foreach (array_keys($parameters) as $key) {
                    if (str_starts_with(strtolower((string) $key), 'utm_')
                        || in_array(strtolower((string) $key), self::TRACKING_PARAMETERS, true)
                        || UrlSanitizer::isSensitiveKey((string) $key)) {
                        unset($parameters[$key]);
                    }
                }
                ksort($parameters);
                $query = http_build_query($parameters);
            }

            $host = strtolower($uri->getHost());
            $scheme = strtolower($uri->getScheme());
            $port = $uri->getPort();
            $defaultPort = match ($scheme) {
                'http' => 80,
                'https' => 443,
                default => null,
            };
            if ($host !== '' && $port !== null && $port !== $defaultPort) {
                $host .= ':' . $port;
            }
            $normalized = $host . $path;
            if ($query !== '') {
                $normalized .= '?' . $query;
            }
            if ($uri->getFragment() !== '') {
                $normalized .= '#' . $uri->getFragment();
            }

            return $normalized !== '' ? $normalized : '/';
        } catch (\Throwable) {
            return $this->normalizeText($value);
        }
    }

    /**
     * @return array{node: \DOMElement, tag: string, id: string, classes: list<string>, position: int}
     */
    private function facts(Element $element): array
    {
        $node = $element->node();
        $key = spl_object_id($node);

        return $this->factCache[$key] ??= [
            'node' => $node,
            'tag' => $element->tag(),
            'id' => (string) $element->attr('id'),
            'classes' => $this->stableCandidateClasses($element->classTokens()),
            'position' => $element->siblingElementPosition(),
        ];
    }

    /**
     * @param array{tag: string, id: string, classes: list<string>, position?: int} $expected
     * @param array{tag: string, id: string, classes: list<string>, position: int} $actual
     */
    private function signatureSimilarity(array $expected, array $actual): float
    {
        $parts = [
            [$this->tagSimilarity((string) ($expected['tag'] ?? ''), $actual['tag']), 0.45],
        ];
        if (($expected['id'] ?? '') !== '') {
            $parts[] = [$this->textSimilarity((string) $expected['id'], $actual['id']), 0.30];
        }
        if (($expected['classes'] ?? []) !== []) {
            $parts[] = [$this->setSimilarity($expected['classes'], $actual['classes']), 0.20];
        }
        if (($expected['position'] ?? 0) > 0) {
            $actualPosition = $actual['position'];
            $parts[] = [$actualPosition > 0 ? 1 / (1 + abs($expected['position'] - $actualPosition)) : 0.0, 0.05];
        }

        $score = 0.0;
        $weight = 0.0;
        foreach ($parts as [$similarity, $partWeight]) {
            $score += $similarity * $partWeight;
            $weight += $partWeight;
        }

        return $weight > 0.0 ? $score / $weight : 0.0;
    }

    /**
     * @param list<array{tag: string, id: string, classes: list<string>, position?: int}> $expected
     * @param list<array{tag: string, id: string, classes: list<string>, position: int}> $actual
     */
    private function ancestorSimilarity(array $expected, array $actual): float
    {
        if ($expected === [] || $actual === []) {
            return 0.0;
        }

        $expected = array_slice($expected, 0, 6);
        $actual = array_slice($actual, 0, 8);
        $expectedCount = count($expected);
        $actualCount = count($actual);
        $matrix = array_fill(0, $expectedCount + 1, array_fill(0, $actualCount + 1, 0.0));

        // Fuzzy LCS alignment tolerates wrappers inserted into the DOM.
        for ($i = 1; $i <= $expectedCount; $i++) {
            for ($j = 1; $j <= $actualCount; $j++) {
                $similarity = $this->signatureSimilarity($expected[$i - 1], $actual[$j - 1]);
                $match = $matrix[$i - 1][$j - 1] + $similarity;
                $matrix[$i][$j] = max($matrix[$i - 1][$j], $matrix[$i][$j - 1], $match);
            }
        }

        return min(1.0, $matrix[$expectedCount][$actualCount] / max($expectedCount, $actualCount));
    }

    /**
     * @param list<array{tag: string, text: string, classes: list<string>}> $expected
     * @param list<array{tag: string, text: string, classes: list<string>}> $actual
     */
    private function siblingSimilarity(array $expected, array $actual): float
    {
        if ($expected === [] || $actual === []) {
            return 0.0;
        }

        $bestScores = [];
        foreach ($expected as $expectedSibling) {
            $best = 0.0;
            foreach ($actual as $actualSibling) {
                $score = 0.50 * $this->tagSimilarity($expectedSibling['tag'], $actualSibling['tag']);
                $weight = 0.50;
                if ($expectedSibling['text'] !== '' && $actualSibling['text'] !== '') {
                    $score += 0.35 * $this->textSimilarity($expectedSibling['text'], $actualSibling['text']);
                    $weight += 0.35;
                }
                if ($expectedSibling['classes'] !== [] || $actualSibling['classes'] !== []) {
                    $score += 0.15 * $this->setSimilarity(
                        $expectedSibling['classes'],
                        $this->stableCandidateClasses($actualSibling['classes']),
                    );
                    $weight += 0.15;
                }
                $best = max($best, $score / $weight);
            }
            $bestScores[] = $best;
        }

        return array_sum($bestScores) / count($bestScores);
    }

    private function textSimilarity(string $expected, string $actual): float
    {
        $expected = $this->normalizeText($expected);
        $actual = $this->normalizeText($actual);
        if ($expected === '' || $actual === '') {
            return 0.0;
        }
        if ($expected === $actual) {
            return 1.0;
        }

        $tokenScore = $this->multisetDice($this->tokens($expected), $this->tokens($actual));
        $characterScore = $this->multisetDice($this->characterNgrams($expected), $this->characterNgrams($actual));
        $score = (0.68 * $tokenScore) + (0.32 * $characterScore);

        // A saved label may gain a suffix/prefix after a redesign (for example a badge).
        if (str_contains($actual, $expected) || str_contains($expected, $actual)) {
            $shorter = min($this->unicodeLength($expected), $this->unicodeLength($actual));
            $longer = max($this->unicodeLength($expected), $this->unicodeLength($actual));
            $containment = $shorter / max(1, $longer);
            $score = max($score, 0.88 * $containment);
        }

        return min(1.0, max(0.0, $score));
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     */
    private function setSimilarity(array $expected, array $actual): float
    {
        $expected = array_values(array_unique(array_filter($expected, 'is_string')));
        $actual = array_values(array_unique(array_filter($actual, 'is_string')));
        if ($expected === [] || $actual === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($expected, $actual));

        return (2 * $intersection) / (count($expected) + count($actual));
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     */
    private function multisetDice(array $expected, array $actual): float
    {
        if ($expected === [] || $actual === []) {
            return 0.0;
        }

        $expectedCounts = array_count_values($expected);
        $actualCounts = array_count_values($actual);
        $intersection = 0;
        foreach ($expectedCounts as $token => $count) {
            $intersection += min($count, $actualCounts[$token] ?? 0);
        }

        return (2 * $intersection) / (count($expected) + count($actual));
    }

    /** @return list<string> */
    private function characterNgrams(string $text): array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) {
            $characters = str_split($text);
        }

        $characterCount = count($characters);
        $size = min(3, max(1, $characterCount - 1));
        if ($characterCount <= $size) {
            return $characters;
        }

        $ngrams = [];
        for ($index = 0, $last = $characterCount - $size; $index <= $last; $index++) {
            $ngrams[] = implode('', array_slice($characters, $index, $size));
        }

        return $ngrams;
    }

    private function normalizeText(string $text): string
    {
        $text = preg_replace('/[\p{Z}\s]+/u', ' ', trim($text)) ?? trim($text);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($text, 'UTF-8');
        }

        return strtolower($text);
    }

    private function unicodeLength(string $text): int
    {
        $count = preg_match_all('/./us', $text);

        return $count === false ? strlen($text) : $count;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [] : $tokens;
    }

    /**
     * @param list<string> $classes
     * @return list<string>
     */
    private function stableCandidateClasses(array $classes): array
    {
        return array_values(array_filter($classes, static fn (string $class): bool => !ElementSnapshot::isVolatileClass($class)));
    }
}
