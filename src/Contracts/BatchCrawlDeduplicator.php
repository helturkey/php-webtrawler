<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Contracts;

/** A CrawlDeduplicator that can record many request fingerprints in one round trip (one transaction). */
interface BatchCrawlDeduplicator extends CrawlDeduplicator
{
    /**
     * Mark every fingerprint as seen and report, per input position, whether it was already known. A fingerprint
     * repeated inside the batch is reported as a duplicate from its second occurrence on.
     *
     * @param list<string> $fingerprints
     * @return list<bool> true = duplicate, in the same order as $fingerprints
     */
    public function markUrlsSeenBatch(string $scope, array $fingerprints): array;
}
