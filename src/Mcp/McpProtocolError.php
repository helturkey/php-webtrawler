<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Mcp;

/** @internal JSON-RPC level failure (as opposed to a tool that ran and failed). */
final class McpProtocolError extends \RuntimeException
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly int $errorCode,
        string $message,
        public readonly array $data = [],
    ) {
        parent::__construct($message);
    }
}
