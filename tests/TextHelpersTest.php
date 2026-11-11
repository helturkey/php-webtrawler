<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Browser\NetworkMetadata;
use HElturkey\WebTrawler\Parsing\Element;
use HElturkey\WebTrawler\Support\Text;
use PHPUnit\Framework\TestCase;

final class TextHelpersTest extends TestCase
{
    public function test_byte_truncation_never_splits_a_multibyte_character(): void
    {
        foreach (['ééé', 'مرحبا', 'ab😀c'] as $text) {
            for ($limit = 0; $limit <= strlen($text); $limit++) {
                $cut = Text::truncateBytes($text, $limit);
                self::assertLessThanOrEqual($limit, strlen($cut));
                self::assertSame(1, preg_match('//u', $cut), "limit $limit of $text");
            }
        }
    }

    public function test_network_metadata_truncation_stays_json_encodable(): void
    {
        $truncated = NetworkMetadata::truncate(str_repeat('é', 10), 5);

        self::assertSame('éé', $truncated);
        self::assertNotFalse(json_encode($truncated));
    }

    public function test_limit_text_supports_limits_beyond_the_pcre_quantifier_cap(): void
    {
        $text = str_repeat('é', 70_000);

        self::assertSame(69_999, mb_strlen(Element::limitText($text, 69_999)));
        self::assertSame(70_000, mb_strlen(Element::limitText($text, 80_000)));
    }

    public function test_scrub_replaces_invalid_sequences(): void
    {
        self::assertSame(1, preg_match('//u', Text::scrubUtf8("a\xB1b")));
    }
}
