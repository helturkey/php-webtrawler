<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Spider;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Fetching\Fetcher;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Spider\SitemapSpider;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Spider\SpiderRunner;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

final class SitemapSpiderTest extends TestCase
{
    private function transport(): RecordingTransport
    {
        $ns = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';

        return new RecordingTransport([
            'https://blog.test/sitemap.xml' => [200, '<?xml version="1.0"?><sitemapindex ' . $ns . '><sitemap><loc>https://blog.test/posts.xml</loc></sitemap><sitemap><loc>https://blog.test/pages.xml</loc></sitemap></sitemapindex>'],
            'https://blog.test/posts.xml' => [200, '<?xml version="1.0"?><urlset ' . $ns . '><url><loc>https://blog.test/post/1</loc></url><url><loc>https://blog.test/post/2</loc></url><url><loc>https://blog.test/about</loc></url><url><loc>https://evil.test/post/9</loc></url></urlset>'],
            'https://blog.test/pages.xml' => [200, '<?xml version="1.0"?><urlset ' . $ns . '><url><loc>https://blog.test/post/3</loc></url></urlset>'],
            'https://blog.test/post/1' => [200, '<h1>One</h1>'],
            'https://blog.test/post/2' => [200, '<h1>Two</h1>'],
        ]);
    }

    private function spider(bool $crossHost = false): SitemapSpider
    {
        return new class ($crossHost) extends SitemapSpider {
            protected array $sitemapUrls = ['https://blog.test/sitemap.xml'];
            protected array $sitemapRules = ['#/post/#' => 'parsePost'];
            protected array $sitemapFollow = ['#posts\.xml$#'];

            public function __construct(bool $crossHost)
            {
                $this->allowCrossHostEntries = $crossHost;
            }

            protected function parsePost(Page $page, SpiderRequest $request): iterable
            {
                yield ['title' => $page->css('h1')->first()?->text()];
            }
        };
    }

    public function test_it_follows_the_index_and_routes_entries_to_callbacks(): void
    {
        $transport = $this->transport();
        $result = (new SpiderRunner(Fetcher::create($transport, new InMemorySnapshotStore(), new AdaptiveMatcher())))->run($this->spider());

        $titles = array_column($result->items, 'title');
        sort($titles);
        self::assertSame(['One', 'Two'], $titles);
        self::assertNotContains('https://blog.test/pages.xml', $transport->urls(), 'sitemapFollow excludes it.');
        self::assertNotContains('https://blog.test/about', $transport->urls(), 'No sitemap rule matches /about.');
        self::assertNotContains('https://evil.test/post/9', $transport->urls(), 'A sitemap may only list URLs on its own host by default.');
        self::assertSame([], $result->errors);
    }

    public function test_cross_host_entries_require_an_explicit_opt_in(): void
    {
        $transport = $this->transport();
        (new SpiderRunner(Fetcher::create($transport, new InMemorySnapshotStore(), new AdaptiveMatcher())))->run($this->spider(crossHost: true));

        self::assertContains('https://evil.test/post/9', $transport->urls());
    }
}
