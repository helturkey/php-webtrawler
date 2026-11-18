<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Exception;

use HElturkey\WebTrawler\Contracts\TransientFailure;

/** Transport-level failure (timeout, connection refused/reset, body read error). Safe to retry. */
class NetworkException extends ScraperException implements TransientFailure
{
}
