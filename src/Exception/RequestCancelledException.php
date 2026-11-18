<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Exception;

/** A request was deliberately abandoned by its caller, not failed by the network or proxy. */
final class RequestCancelledException extends ScraperException
{
}
