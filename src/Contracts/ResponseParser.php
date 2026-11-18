<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Contracts;

use HElturkey\WebTrawler\Response\FetchResponse;
use HElturkey\WebTrawler\Parsing\Page;

/**
 * Turns a downloaded response into a queryable Page. Transports never parse: whatever produced the
 * FetchResponse (Guzzle, Amp, a browser, a custom driver), parsing happens here, after the download.
 */
interface ResponseParser
{
    /** @param string|null $encoding Forces a charset instead of header/<meta> detection. */
    public function parse(FetchResponse $response, ?string $encoding = null): Page;
}
