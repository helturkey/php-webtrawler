<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Contracts;

interface CrawlDeduplicator
{
    /** Returns true only when the request fingerprint already exists in this crawl scope. */
    public function markUrlSeen(string $scope, string $fingerprint): bool;

    /** Records the exact response body hash and returns true when another URL in this scope has the same body. */
    public function recordContent(string $scope, string $url, string $contentHash, int $sizeBytes): bool;

    /** Remove all URL and content records belonging to a completed or abandoned crawl. */
    public function clearScope(string $scope): void;
}
