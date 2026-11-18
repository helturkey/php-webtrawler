<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Contracts\Http;

/** Optional capability to abandon pending results and delegate physical cancellation where supported. */
interface CancellableTransportInterface extends AsyncTransportInterface
{
    /** Abandon in-flight results; already settled requests remain unchanged. Implementations should cancel underlying I/O when possible. */
    public function cancelAll(): void;
}
