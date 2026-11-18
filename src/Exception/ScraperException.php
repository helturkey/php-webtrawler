<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Exception;

use RuntimeException;

/** Base class for runtime failures raised by the scraper itself (not by PHP or an HTTP library). */
class ScraperException extends RuntimeException
{
}
