<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Contracts;

/** Marker for failures that are worth retrying (timeouts, refused/reset connections, empty proxy pool ...). */
interface TransientFailure extends \Throwable
{
}
