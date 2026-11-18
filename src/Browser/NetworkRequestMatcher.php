<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Browser;

/** Correlates response events with the most recent pending request record. */
final class NetworkRequestMatcher
{
    /**
     * @param list<array<string, mixed>> $requests
     * @param list<string> $rawUrls
     */
    public static function findPendingIndex(
        string $url,
        ?string $method,
        ?string $resourceType,
        array $requests,
        array $rawUrls,
    ): ?int {
        $match = null;

        for ($index = count($requests) - 1; $index >= 0; --$index) {
            $request = $requests[$index];

            if (($request['status'] ?? null) !== null || isset($request['failure'])) {
                continue;
            }

            if (($rawUrls[$index] ?? null) !== $url) {
                continue;
            }

            if ($method !== null && ($request['method'] ?? null) !== $method) {
                continue;
            }

            if ($resourceType !== null && ($request['resource_type'] ?? null) !== $resourceType) {
                continue;
            }

            // URL-only correlation is safe only when exactly one pending
            // request matches. Do not attach a response to an arbitrary request
            // when identical requests are in flight concurrently.
            if ($match !== null) {
                return null;
            }

            $match = $index;
        }

        return $match;
    }
}
