<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Exception;

/** Every proxy in the pool is cooling down after repeated failures. */
final class ProxyPoolExhaustedException extends NetworkException
{
}
