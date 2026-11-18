<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Spider;

/**
 * One crawling rule of a CrawlSpider: which links to look at and what to do with the pages they lead to.
 *
 * @param string|null $callback Name of a public or protected CrawlSpider method with the signature
 *                              (Page $page, SpiderRequest $request): iterable. null means "follow only".
 * @param bool|null $follow Whether links matched by this rule are followed. Default: follow when there is no
 *                          callback (a navigation rule), do not follow when there is one (a leaf rule).
 */
final readonly class Rule
{
    public function __construct(
        public LinkExtractor $extractor,
        public ?string $callback = null,
        private ?bool $follow = null,
    ) {
        if ($callback !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $callback) !== 1) {
            throw new \InvalidArgumentException('A rule callback must be a method name.');
        }
    }

    public function follows(): bool
    {
        return $this->follow ?? $this->callback === null;
    }
}
