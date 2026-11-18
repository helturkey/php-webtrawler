<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Parsing;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Contracts\ResponseParser;
use HElturkey\WebTrawler\Contracts\SnapshotStore;
use HElturkey\WebTrawler\Response\FetchResponse;

/** Default parser: HTML5 document tree wired to the snapshot store and the adaptive matcher. */
final readonly class HtmlPageParser implements ResponseParser
{
    public function __construct(
        private SnapshotStore $snapshotStore,
        private AdaptiveMatcher $adaptiveMatcher,
    ) {
    }

    public function parse(FetchResponse $response, ?string $encoding = null): Page
    {
        return Page::fromResponse($response, $this->snapshotStore, $this->adaptiveMatcher, $encoding);
    }
}
