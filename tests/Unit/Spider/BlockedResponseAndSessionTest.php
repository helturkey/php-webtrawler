<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Unit\Spider;

use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Response\BlockDetector;
use HElturkey\WebTrawler\Response\BlockSignal;
use HElturkey\WebTrawler\Scraper;
use HElturkey\WebTrawler\Spider\RequestFingerprint;
use HElturkey\WebTrawler\Spider\Spider;
use HElturkey\WebTrawler\Spider\SpiderRequest;
use HElturkey\WebTrawler\Tests\Support\RecordingTransport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BlockedResponseAndSessionTest extends TestCase
{
    private function spider(bool $escalate): Spider
    {
        return new class ($escalate) extends Spider {
            public function __construct(private bool $escalate)
            {
                $this->blockDetector = BlockDetector::default();
            }

            public function startRequests(): iterable
            {
                yield SpiderRequest::get('https://site.test/a');
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                yield ['title' => $page->css('h1')->first()?->text(), 'session' => $request->session];
            }

            public function onBlocked(Page $page, SpiderRequest $request, BlockSignal $signal): iterable
            {
                if ($this->escalate && $request->session === null) {
                    yield $request->viaSession('browser');
                }
            }
        };
    }

    private function blockedTransport(): RecordingTransport
    {
        return new RecordingTransport(['https://site.test/a' => [403, '<html><title>Just a moment...</title></html>']]);
    }

    public function test_a_blocked_response_is_counted_and_never_parsed(): void
    {
        $result = Scraper::create($this->blockedTransport())->crawl($this->spider(escalate: false));

        self::assertSame(1, $result->blockedResponses);
        self::assertSame(0, $result->requestsCompleted);
        self::assertSame([], $result->items);
        self::assertSame('BlockedResponse', $result->errors[0]['exception']);
        self::assertStringContainsString('cloudflare', $result->errors[0]['message']);
    }

    public function test_a_blocked_page_can_be_escalated_to_another_session(): void
    {
        $primary = $this->blockedTransport();
        $browser = new RecordingTransport(['https://site.test/a' => [200, '<h1>Real content</h1>']]);

        $result = Scraper::create($primary)->crawl($this->spider(escalate: true), sessions: ['browser' => $browser]);

        self::assertSame([['title' => 'Real content', 'session' => 'browser']], $result->items);
        self::assertSame(['https://site.test/a'], $primary->urls());
        self::assertSame(['https://site.test/a'], $browser->urls(), 'The same URL is fetched again through the session.');
        self::assertSame(1, $result->blockedResponses);
        self::assertSame(1, $result->requestsCompleted);
    }

    public function test_an_unknown_session_is_reported_as_an_error(): void
    {
        $spider = new class () extends Spider {
            public function startRequests(): iterable
            {
                yield SpiderRequest::get('https://site.test/a')->viaSession('nope');
            }

            public function parse(Page $page, SpiderRequest $request): iterable
            {
                return [];
            }
        };

        $result = Scraper::create(new RecordingTransport())->crawl($spider);

        self::assertCount(1, $result->errors);
        self::assertStringContainsString('Unknown spider session "nope"', $result->errors[0]['message']);
    }

    public function test_the_session_is_part_of_the_request_identity(): void
    {
        $plain = SpiderRequest::get('https://site.test/a');

        self::assertNotSame(RequestFingerprint::for($plain), RequestFingerprint::for($plain->viaSession('browser')));
        self::assertSame(RequestFingerprint::for($plain), RequestFingerprint::for($plain->viaSession(null)));
        self::assertSame('browser', $plain->viaSession('browser')->withDepth(3)->session, 'Copies keep the session.');
    }

    public function test_session_names_are_validated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SpiderRequest::get('https://site.test/a')->viaSession('Bad Name');
    }

    public function test_scraper_rejects_malformed_session_registrations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Scraper::create(new RecordingTransport())->crawl($this->spider(escalate: false), sessions: ['Bad Name' => new RecordingTransport()]);
    }
}
