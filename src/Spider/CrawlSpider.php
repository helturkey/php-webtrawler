<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Spider;

use HElturkey\WebTrawler\Parsing\Page;

/**
 * A spider driven by declarative rules instead of hand-written link handling.
 *
 * ```php
 * final class ShopSpider extends CrawlSpider
 * {
 *     protected array $startUrls = ['https://shop.example/'];
 *     protected array $allowedHosts = ['shop.example'];
 *
 *     protected function rules(): array
 *     {
 *         return [
 *             new Rule(new LinkExtractor(allow: ['#/category/#'])),                 // navigate
 *             new Rule(new LinkExtractor(allow: ['#/product/\d+#']), 'parseProduct'), // extract
 *         ];
 *     }
 *
 *     protected function parseProduct(Page $page, SpiderRequest $request): iterable
 *     {
 *         yield ['url' => $page->url(), 'name' => $page->css('h1')->first()?->text()];
 *     }
 * }
 * ```
 *
 * Every page is scanned with every rule's extractor and the links found become requests. A response is routed by
 * its own URL: the first rule whose extractor accepts the URL is the rule that "reached" it; its callback (if any)
 * handles the page, and when the rule does not follow, links on that page are not scanned. This keeps the spider
 * stateless, so checkpoints and resumed crawls need no extra data. Start URLs go to parseStart() and are always
 * scanned. Rules match on URL patterns, domains and extensions; restrictCss only narrows where links are
 * *discovered*, not how a page is routed.
 */
abstract class CrawlSpider extends Spider
{
    /** @return list<Rule> */
    abstract protected function rules(): array;

    /**
     * Handle a start URL's page (links are followed regardless).
     *
     * @return iterable<mixed>
     */
    protected function parseStart(Page $page, SpiderRequest $request): iterable
    {
        return [];
    }

    /** @return iterable<SpiderRequest|SpiderItem|array<string, mixed>> */
    final public function parse(Page $page, SpiderRequest $request): iterable
    {
        $rules = $this->rules();

        $reachedBy = null;
        if ($request->depth === 0) {
            yield from $this->parseStart($page, $request);
        } else {
            foreach ($rules as $rule) {
                if ($rule->extractor->matches($page->url())) {
                    $reachedBy = $rule;
                    break;
                }
            }
            if ($reachedBy?->callback !== null) {
                if (!method_exists($this, $reachedBy->callback)) {
                    throw new \LogicException(sprintf('Rule callback %s::%s() does not exist.', static::class, $reachedBy->callback));
                }
                /** @var iterable<mixed> $outputs */
                $outputs = $this->{$reachedBy->callback}($page, $request);
                yield from $outputs;
            }
        }

        if ($reachedBy !== null && !$reachedBy->follows()) {
            return;
        }
        foreach ($rules as $rule) {
            foreach ($rule->extractor->extract($page) as $url) {
                yield SpiderRequest::get($url);
            }
        }
    }
}
