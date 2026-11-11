<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Exception;

/** The URL targets a private, loopback, link-local or otherwise non-public network address. */
final class UnsafeUrlException extends ScraperException
{
}
