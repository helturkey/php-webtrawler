# Crawling toolkit: robots.txt, rules, sitemaps, sessions, streaming, Markdown

This page covers the higher-level crawling features. Everything here is opt-in; a plain `Spider` behaves exactly as
before.

## robots.txt

```php
final class BooksSpider extends Spider
{
    protected array $startUrls = ['https://books.example/'];
    protected bool $respectRobotsTxt = true;
    protected ?string $userAgent = 'BooksBot/1.0 (+https://my.site/bot)';
    // ...
}
```

With `respectRobotsTxt` the runner fetches `/robots.txt` once per origin (scheme + host + port), caches it for 24 hours
and checks every request before it is downloaded.

* `Allow`/`Disallow` follow RFC 9309: the longest matching rule wins, `Allow` wins a tie, `*` is a wildcard and a
  trailing `$` anchors the end. Matching is a linear glob, never a regular expression, so a hostile file cannot cause
  catastrophic backtracking. At most 500 KiB and 10,000 rules are read.
* `Crawl-delay` raises the per-host delay (it never lowers a delay you configured).
* The group is chosen by the **product token** of `$userAgent` (`BooksBot`); with no `$userAgent` the default
  `HElturkey-WebTrawler` token is used. The same string is sent as the `User-Agent` header, so what you declare
  and what you send cannot diverge.
* Availability follows the RFC: `2xx` is parsed, `4xx` means "no restrictions", and a server error or unreachable
  host means "assume everything is disallowed" (cached only 5 minutes, so the crawl recovers).
* URL-policy violations (private address, host not allowed) are **not** hidden behind a disallow: they surface as
  the real error.
* Skipped requests are counted in `CrawlResult::$robotsBlocked` and listed in `$errors` with the class
  `RobotsDisallowed`.

The fetch is synchronous (once per origin per day) and goes through the same security policy as every request.
Used on its own: `RobotsTxt::parse($text)->isAllowed('MyBot', '/path?x=1')` and `RobotsPolicy`.

From the CLI: `scraper crawl spider.php --robots --user-agent "BooksBot/1.0"`.

## LinkExtractor, CrawlSpider and Rule

`LinkExtractor` returns the absolute, de-duplicated, fragment-free http(s) links of a page that pass your filters:

```php
$extractor = new LinkExtractor(
    allow: ['#/product/\d+#'],       // at least one must match (empty = any)
    deny: ['#/product/\d+/print#'],
    allowDomains: ['shop.example'],   // hosts and their subdomains
    restrictCss: 'main',              // only look inside <main>
    skipNofollow: true,
);
$links = $extractor->extract($page);  // list<string>
$extractor->matches('https://shop.example/product/1'); // test a URL without a page
```

Static assets, media and archives (`jpg`, `pdf`, `zip`, `css`, `js`, ...) are skipped by default; pass
`denyExtensions: []` to keep them. Patterns are validated when the extractor is constructed.

`CrawlSpider` turns extractors into a crawl:

```php
final class ShopSpider extends CrawlSpider
{
    protected array $startUrls = ['https://shop.example/'];
    protected array $allowedHosts = ['shop.example'];

    protected function rules(): array
    {
        return [
            new Rule(new LinkExtractor(allow: ['#/category/#'])),                    // navigate
            new Rule(new LinkExtractor(allow: ['#/product/\d+#']), 'parseProduct'),  // extract
        ];
    }

    protected function parseProduct(Page $page, SpiderRequest $request): iterable
    {
        yield ['url' => $page->url(), 'name' => $page->css('h1')->first()?->text()];
    }
}
```

Semantics (the same as Scrapy's `CrawlSpider`): every page is scanned with every rule's extractor; a response is
routed by its URL to the **first rule whose extractor accepts it**; that rule's callback handles the page, and when
the rule does not follow (`follow` defaults to *true only when there is no callback*) the page's own links are not
scanned. Start URLs go to `parseStart()` and are always scanned. Routing uses only the URL, so checkpoints and
resumed crawls need no extra state. Callbacks must be public or protected methods.

## SitemapSpider

```php
final class BlogSpider extends SitemapSpider
{
    protected array $sitemapUrls = ['https://blog.example/sitemap.xml'];
    protected array $sitemapRules = ['#/posts/#' => 'parsePost'];   // pattern => callback
    protected array $sitemapFollow = ['#posts-\d+\.xml$#'];          // which child sitemaps of an index to open

    protected function parsePost(Page $page, SpiderRequest $request): iterable { yield [...]; }
}
```

Sitemap indexes are followed, `.xml.gz` is decompressed (capped at 50 MiB) and at most 50,000 entries are read per
file. Only URLs that match a rule are fetched. A sitemap may list only URLs **on its own host** (as the protocol
requires); set `protected bool $allowCrossHostEntries = true;` to relax that. The XML parser refuses entity
declarations and never touches the network (`SitemapParser`).

## Blocked responses (detection, not evasion)

```php
protected ?BlockDetector $blockDetector = null;   // set to BlockDetector::default() to enable

public function onBlocked(Page $page, SpiderRequest $request, BlockSignal $signal): iterable
{
    // $signal->vendor: cloudflare | akamai | datadome | perimeterx | imperva | captcha | rate-limit | generic
    if ($request->session === null) {
        yield $request->viaSession('browser');   // try the same URL through a real browser
    }
}
```

`BlockDetector` recognises challenge pages, WAF denials and rate limits (a `cf-mitigated` header, HTTP 429, and
well-known markers on 401/403/406/503 pages). On a 2xx response only Cloudflare's own interstitial
("Just a moment...", `_cf_chl_opt`) counts, so a login form that merely embeds a CAPTCHA widget is not mistaken for
a block. A blocked response is **not parsed** (a challenge page would produce empty or wrong items); it is counted in
`CrawlResult::$blockedResponses`, listed in `$errors` as `BlockedResponse`, and passed to `onBlocked()`.

The library detects blocks; it does **not** solve CAPTCHAs or Turnstile challenges and does not try to defeat a
site's access controls. What it offers instead: browser-like TLS/HTTP2 fingerprints through the optional Rnet and
Impity transports (`--emulation` on the CLI), polite pacing (`AutoThrottle`, `Crawl-delay`), proxy pools, and the
session mechanism below so a page you are entitled to read can be re-fetched with a real browser.

## Sessions: HTTP and a browser in one spider

```php
$scraper = Scraper::create(new GuzzleTransport());
$result = $scraper->crawl(
    $spider,
    sessions: ['browser' => new BrowserTransport(new PlaywrightBrowserDriver(), ['wait_until' => 'networkidle'])],
);
```

A request chooses a session with `SpiderRequest::get($url)->viaSession('browser')`. Sessions share the scraper's
security policy, retry defaults and parser. Requests that use a session are downloaded one at a time even in a
concurrent crawl (a browser is not driven by the shared event loop), so route only the pages that need it. The
session name is part of the request's identity: the escalated request is not discarded as a duplicate of the
original, and the name is saved in checkpoints.

## Streaming

```php
$stream = $scraper->stream($spider, pipelines: [new JsonlAppendPipeline(__DIR__ . '/out.jsonl')]);
foreach ($stream as $item) {
    handle($item);                 // arrives as soon as the page is parsed
}
$result = $stream->getReturn();    // CrawlResult (its items list is empty: nothing is buffered)
```

Memory stays flat however large the crawl is. Items still pass through your pipelines first. `break` stops the crawl
and runs the normal cleanup. Internally the crawl runs in a PHP Fiber; do not suspend that fiber yourself, and prefer
a non-Revolt transport with `stream()` (the Amp transport is not covered by the streaming tests).

## Large frontiers: batched SQLite writes

`SQLiteCrawlDeduplicator` implements `BatchCrawlDeduplicator`. When a page yields many links the runner now marks
them all in **one transaction** instead of one write per URL. Behaviour is unchanged (same pages, same order, same
page budget); only the number of commits drops. `benchmarks/frontier.php` measures the three strategies on your
hardware; no numbers are committed because they depend on the disk. Enable it with
`--dedupe-db frontier.sqlite` or `crawl(crawlDeduplicator: new SQLiteCrawlDeduplicator($path))`.

## Development cache

```bash
bin/scraper fetch https://example.com --cache-dir .cache --markdown          # downloads
bin/scraper fetch https://example.com --cache-dir .cache --markdown          # served from disk
bin/scraper fetch https://example.com --cache-dir .cache --offline           # never touches the network
```

`CachingTransport` stores GET responses on disk (directory 0700, files 0600, atomic writes) so you can rewrite
`parse()` and re-run against the same pages instantly, or replay a bug offline. It ignores `Cache-Control`, caches
statuses below 500 except 429, supports an optional TTL, is blocking only (no concurrency) and is **for development**:
cached files may contain cookies. `--offline` turns a missing entry into an error. In code:

```php
$scraper = Scraper::create(new CachingTransport(new GuzzleTransport(), __DIR__ . '/.cache', ttlSeconds: 3600));
```

## Markdown for RAG and language models

```php
$page->markdown();                          // the body
$page->markdown('article.post');            // part of the page
$page->markdown(mainContent: true);         // <main>/<article>, without nav/footer/aside
$page->css('.card')->first()->markdown();   // one element
```

CommonMark plus GitHub tables: headings, emphasis, links (made absolute), images, nested and ordered lists, fenced
code (with the language), blockquotes, tables. Scripts, styles, frames and form controls are dropped, and so is
content hidden from readers (`hidden`, `aria-hidden="true"`, inline `display:none`/`visibility:hidden`); only
`http`, `https`, `mailto` and `tel` link targets survive and `data:`/`javascript:` URLs are removed. That is best
effort: text hidden by an external stylesheet cannot be detected, so treat scraped pages as untrusted input to a
model. Whitespace inside code blocks is preserved exactly. From the CLI: `fetch URL --markdown [--select CSS]
[--main-content]`; from MCP: `fetch_page` with `"format": "markdown"`.

## Interactive shell

```text
$ bin/scraper shell https://example.com
> css h1
[0] <h1> Example Domain
> attr href a
https://www.iana.org/domains/example
> links
> markdown
> quit
```

Commands: `fetch`, `url`, `title`, `text`, `css`, `xpath`, `count`, `attr <name> <selector>`, `links`, `markdown
[selector]`, `help`, `quit`. It is a fixed command set read line by line, **not a PHP evaluator**: nothing you type
is executed, and every download goes through the normal security policy (`--block-private`, `--max-bytes`, ...).
Use it to find the selectors, then copy them into a spider.

## Docker

```bash
docker build -f docker/Dockerfile -t webtrawler .
docker run --rm webtrawler fetch https://example.com --block-private --markdown
docker run --rm -v "$PWD/spiders:/spiders:ro" -v "$PWD/out:/out" webtrawler \
  crawl /spiders/books.php --format jsonl --output /out/books.jsonl --state /out/books.state.json --robots
```

The single `docker/Dockerfile` provides the production `runtime` target and a unified `ci` target. Run `composer test-docker` to build the production image, execute its smoke checks, build one AMD64 CI image, and run the browser, Impity, and php-rnet integration suites sequentially in one isolated container. The CI image is for validation only and must not be deployed.

The production image contains production dependencies only, runs as an unprivileged user, writes crawl output under `/out`, disables assertions, and caps PHP memory at 512 MB. See the [Docker deployment and CI guide](docker.md) for build, volume, security, and troubleshooting details.
