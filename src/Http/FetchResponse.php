<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Http;

use JsonException;

final readonly class FetchResponse
{
    /**
     * @param array<string, list<string>> $headers
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public string $requestedUrl,
        public string $effectiveUrl,
        public float $durationMs,
        public array $meta = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $headerName => $values) {
            if (strcasecmp($headerName, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    /** @throws JsonException */
    public function json(bool $associative = true): mixed
    {
        return json_decode($this->body, $associative, 512, JSON_THROW_ON_ERROR);
    }
}
