<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Adaptive\AdaptiveScoringProfile;
use HElturkey\WebTrawler\Adaptive\ElementSnapshot;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use PHPUnit\Framework\TestCase;

/**
 * Reference confidences were produced by the unmodified 0.3.x matcher for a candidate whose text has the given
 * length. The default matcher must reproduce them exactly at every size; the optional text cap may only differ
 * above its limit (and only upward).
 */
final class AdaptiveTextSizeTest extends TestCase
{
    private const REFERENCE = [500 => 0.652808, 3999 => 0.387686, 4000 => 0.387681, 4001 => 0.387677, 10000 => 0.352869, 50000 => 0.333105];

    private static function filler(int $chars, bool $reverse = false): string
    {
        $words = explode(' ', 'river bridge trade city harbor market stone canal ferry lantern archive garden tower street guild');
        $words = $reverse ? array_reverse($words) : $words;
        $text = '';
        for ($i = 0; strlen($text) < $chars; $i++) {
            $text .= $words[$i % count($words)] . ($i % 7) . ' ';
        }

        return substr($text, 0, $chars);
    }

    /** @return array{0: Page, 1: ElementSnapshot} */
    private function scenario(int $candidateChars, AdaptiveMatcher $matcher): array
    {
        $url = 'https://example.test/p';
        $store = new InMemorySnapshotStore();
        $head = self::filler(300);
        $before = Page::fromHtml('<main><article class="post"><p class="body">' . $head . '</p></article></main>', $url, $store, $matcher);
        $snapshot = ElementSnapshot::capture($before->css('p.body')->first() ?? self::fail('fixture'), $url);
        $after = Page::fromHtml('<main><article class="entry"><p class="content">' . $head . ' ' . self::filler($candidateChars - 301, true) . '</p></article></main>', $url, $store, $matcher);

        return [$after, $snapshot];
    }

    public function test_the_default_matcher_reproduces_the_reference_scores_at_every_text_size(): void
    {
        $matcher = new AdaptiveMatcher();
        foreach (self::REFERENCE as $size => $expected) {
            [$page, $snapshot] = $this->scenario($size, $matcher);
            $best = $matcher->match($page, $snapshot, 0.52, 0.045, 3)->candidates[0] ?? self::fail('no candidates');

            self::assertEqualsWithDelta($expected, $best->confidence, 1.5e-6, "candidate text of $size characters");
            self::assertSame('content', $best->element()->classTokens()[0], 'The paragraph itself must win.');
        }
    }

    public function test_the_optional_cap_is_exact_up_to_its_limit_and_only_inflates_beyond_it(): void
    {
        $capped = new AdaptiveMatcher(5000, new AdaptiveScoringProfile(), 4000);
        foreach (self::REFERENCE as $size => $expected) {
            [$page, $snapshot] = $this->scenario($size, $capped);
            $confidence = ($capped->match($page, $snapshot, 0.52, 0.045, 3)->candidates[0] ?? self::fail('no candidates'))->confidence;

            if ($size <= 4001) {
                self::assertEqualsWithDelta($expected, $confidence, 1.5e-6, "size $size");
            } else {
                self::assertGreaterThan($expected + 0.01, $confidence, "size $size: truncation removes the length penalty");
                self::assertLessThan($expected + 0.06, $confidence);
            }
        }
    }

    public function test_the_cap_is_validated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AdaptiveMatcher(5000, new AdaptiveScoringProfile(), 100);
    }
}
