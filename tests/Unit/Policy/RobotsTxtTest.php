<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Policy;

use HElturkey\WebTrawler\Policy\RobotsTxt;
use PHPUnit\Framework\TestCase;

final class RobotsTxtTest extends TestCase
{
    private const FILE = <<<'TXT'
# comment line
User-agent: *
Disallow: /private      # trailing comment
Allow: /private/public
Disallow: /*.pdf$
Disallow: /search?
Disallow:

User-agent: HElturkey-WebTrawler
Disallow: /secret
Crawl-delay: 1.5

Sitemap: https://example.test/sitemap.xml
TXT;

    private const OTHER = 'OtherBot/2.0';
    private const OURS = 'HElturkey-WebTrawler: 1.0.0';

    public function test_the_star_group_applies_to_unknown_crawlers(): void
    {
        $robots = RobotsTxt::parse(self::FILE);

        self::assertFalse($robots->isAllowed(self::OTHER, '/private'));
        self::assertFalse($robots->isAllowed(self::OTHER, '/private/area'));
        self::assertTrue($robots->isAllowed(self::OTHER, '/private/public/page'), 'The longer Allow rule wins.');
        self::assertTrue($robots->isAllowed(self::OTHER, '/other'));
        self::assertFalse($robots->isAllowed(self::OTHER, '/doc.pdf'));
        self::assertTrue($robots->isAllowed(self::OTHER, '/doc.pdf?download=1'), 'A "$" anchored rule does not match when a query follows.');
        self::assertFalse($robots->isAllowed(self::OTHER, '/search?q=1'));
        self::assertTrue($robots->isAllowed(self::OTHER, '/search'));
    }

    public function test_a_specific_group_replaces_the_star_group(): void
    {
        $robots = RobotsTxt::parse(self::FILE);

        self::assertTrue($robots->isAllowed(self::OURS, '/private'), 'Our group has no rule for /private.');
        self::assertFalse($robots->isAllowed(self::OURS, '/secret/file'));
        self::assertSame(1.5, $robots->crawlDelaySeconds(self::OURS));
        self::assertNull($robots->crawlDelaySeconds(self::OTHER));
    }

    public function test_sitemaps_are_collected_and_robots_txt_itself_is_always_allowed(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /\n" . "Sitemap: https://example.test/sitemap.xml\n");

        self::assertSame(['https://example.test/sitemap.xml'], $robots->sitemaps());
        self::assertFalse($robots->isAllowed(self::OTHER, '/anything'));
        self::assertTrue($robots->isAllowed(self::OTHER, '/robots.txt'));
    }

    public function test_allow_wins_a_tie_and_an_empty_disallow_allows_everything(): void
    {
        $tie = RobotsTxt::parse("User-agent: *\nDisallow: /a\nAllow: /a\n");
        $open = RobotsTxt::parse("User-agent: *\nDisallow:\n");

        self::assertTrue($tie->isAllowed(self::OTHER, '/a/b'));
        self::assertTrue($open->isAllowed(self::OTHER, '/anything'));
    }

    public function test_several_user_agent_lines_share_one_group(): void
    {
        $robots = RobotsTxt::parse("User-agent: alphabot\nUser-agent: betabot\nDisallow: /x\n");

        self::assertFalse($robots->isAllowed('AlphaBot/1', '/x'));
        self::assertFalse($robots->isAllowed('BetaBot/1', '/x'));
        self::assertTrue($robots->isAllowed('GammaBot/1', '/x'), 'No star group: everything is allowed.');
    }

    public function test_escapes_are_compared_in_normalized_form(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /%7Euser\nDisallow: /a%2fb\n");

        self::assertFalse($robots->isAllowed(self::OTHER, '/~user/page'));
        self::assertFalse($robots->isAllowed(self::OTHER, '/a%2Fb'));
    }

    public function test_unavailable_robots_means_everything_is_disallowed_and_missing_means_allowed(): void
    {
        self::assertFalse(RobotsTxt::disallowAll()->isAllowed(self::OTHER, '/'));
        self::assertFalse(RobotsTxt::disallowAll()->isAllowed(self::OTHER, '/robots.txt'));
        self::assertTrue(RobotsTxt::allowAll()->isAllowed(self::OTHER, '/private'));
    }

    public function test_a_byte_order_mark_and_odd_line_endings_are_tolerated(): void
    {
        $robots = RobotsTxt::parse("\xEF\xBB\xBFUser-agent: *\r\nDisallow: /a\rDisallow: /b\n");

        self::assertFalse($robots->isAllowed(self::OTHER, '/a'));
        self::assertFalse($robots->isAllowed(self::OTHER, '/b'));
    }

    public function test_hostile_wildcard_patterns_finish_quickly(): void
    {
        $pattern = '/' . str_repeat('a*', 300) . 'b';
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: " . $pattern . "\n");

        $started = microtime(true);
        $allowed = $robots->isAllowed(self::OTHER, '/' . str_repeat('a', 3_000));

        self::assertTrue($allowed);
        self::assertLessThan(2.0, microtime(true) - $started, 'Matching must not backtrack catastrophically.');
    }

    public function test_url_helper_includes_the_query_and_product_token_is_extracted(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /search?\n");

        self::assertFalse($robots->isUrlAllowed(self::OTHER, 'https://example.test/search?q=1'));
        self::assertTrue($robots->isUrlAllowed(self::OTHER, 'https://example.test/'));
        self::assertSame('helturkey-webtrawler', RobotsTxt::productToken(self::OURS));
        self::assertSame('mybot', RobotsTxt::productToken('MyBot/1.0 (+https://x.test)'));
    }
}
