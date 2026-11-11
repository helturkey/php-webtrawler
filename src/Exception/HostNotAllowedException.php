<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Exception;

/** The request (or a redirect it followed) targets a host outside the configured allow-list. */
final class HostNotAllowedException extends ScraperException
{
}
