<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Response;

use JsonException;

final readonly class FetchResponse
{
    /** Meta flag: the body is already decoded UTF-8 text (browser output); parsers must not apply header/<meta> charsets. */
    public const META_DECODED = 'decoded';

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

    /** SHA-256 of the exact response bytes exposed to parsers (not a normalized-text fingerprint). */
    public function contentHash(): string
    {
        return hash('sha256', $this->body);
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
