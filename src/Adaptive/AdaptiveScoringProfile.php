<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Adaptive;

/**
 * Defines how much each saved DOM signal contributes to adaptive matching.
 *
 * Scores are normalized against the signals available in a snapshot. They are
 * similarity scores, not calibrated probabilities.
 */
final readonly class AdaptiveScoringProfile
{
    /** @var array<string, float> */
    private const DEFAULT_WEIGHTS = [
        // Element identity.
        'tag' => 0.075,
        'id' => 0.13,
        'data-testid' => 0.19,
        'data-test' => 0.14,
        'data-qa' => 0.14,
        'data-cy' => 0.14,
        'data-attribute' => 0.045,
        'role' => 0.065,
        'aria-label' => 0.12,
        'aria-labelledby' => 0.055,
        'name' => 0.06,
        'title' => 0.055,
        'placeholder' => 0.06,
        'for' => 0.04,
        'href' => 0.065,
        'src' => 0.045,
        'alt' => 0.065,
        'type' => 0.035,
        'itemprop' => 0.055,
        'property' => 0.045,
        'rel' => 0.025,
        'content' => 0.025,

        // Visible identity and local structure.
        'classes' => 0.115,
        'text' => 0.19,
        'own_text' => 0.105,
        'accessible_name' => 0.105,
        'parent' => 0.075,
        'ancestors' => 0.075,
        'siblings' => 0.065,
        'position' => 0.015,
    ];

    /** @var array<string, float> */
    private array $weights;

    /**
     * @param array<string, int|float> $overrides Signal weights to override.
     */
    public function __construct(array $overrides = [])
    {
        $weights = self::DEFAULT_WEIGHTS;

        foreach ($overrides as $signal => $weight) {
            if (!array_key_exists($signal, $weights)) {
                throw new \InvalidArgumentException(sprintf('Unknown adaptive scoring signal "%s".', $signal));
            }
            if (!is_numeric($weight) || !is_finite((float) $weight) || $weight < 0) {
                throw new \InvalidArgumentException(sprintf('Weight for "%s" must be a finite, non-negative number.', $signal));
            }

            $weights[$signal] = (float) $weight;
        }

        if (array_sum($weights) <= 0.0) {
            throw new \InvalidArgumentException('At least one adaptive scoring weight must be greater than zero.');
        }

        $this->weights = $weights;
    }

    public static function balanced(): self
    {
        return new self();
    }

    public function weight(string $signal): float
    {
        return $this->weights[$signal] ?? $this->weights['data-attribute'];
    }

    /** @return array<string, float> */
    public function weights(): array
    {
        return $this->weights;
    }
}
