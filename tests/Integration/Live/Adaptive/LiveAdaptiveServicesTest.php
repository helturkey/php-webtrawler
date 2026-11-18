<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Integration\Live\Adaptive;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Tests\Support\LiveTargets;
use HElturkey\WebTrawler\Tests\Support\LiveTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('live')]
final class LiveAdaptiveServicesTest extends LiveTestCase
{
    public function test_adaptive_matcher_recovers_a_quote_element_from_a_second_live_page_load(): void
    {
        $store = new InMemorySnapshotStore();
        $scraper = Scraper::create(
            snapshotStore: $store,
            adaptiveMatcher: new AdaptiveMatcher(),
            defaultOptions: $this->liveOptions([LiveTargets::QUOTES_HOST]),
            enforceDefaultSecurity: true,
        );

        $source = $scraper->get(LiveTargets::QUOTES_URL);
        $original = $source->css('.quote .text')->first();
        self::assertNotNull($original);
        $source->remember('live-first-quote', $original);

        $current = $scraper->get(LiveTargets::QUOTES_URL);
        $recovered = $current->adaptive('live-first-quote')->first();

        self::assertTrue($recovered->accepted, $recovered->reason ?? 'The quote element should be recovered on the second live page load.');
        self::assertNotSame('', trim((string) $recovered->element()?->text()));
    }
}
