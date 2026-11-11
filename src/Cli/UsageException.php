<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Cli;

/** Raised for invalid command-line usage (exit code 2). */
final class UsageException extends \InvalidArgumentException
{
}
