<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Parsing\Element;
use PHPUnit\Framework\TestCase;

final class UnicodeTruncationTest extends TestCase
{
    public function test_it_does_not_split_multibyte_text_when_limiting_length(): void
    {
        $limited = Element::limitText('مرحبا بالعالم', 5);

        self::assertSame('مرحبا', $limited);
        self::assertSame(5, preg_match_all('/./us', $limited));
    }
}
