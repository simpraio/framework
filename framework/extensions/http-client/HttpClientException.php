<?php

declare(strict_types=1);

namespace extensions\http_client;

use core\http\Url;
use RuntimeException;

final class HttpClientException extends RuntimeException
{
    /** Scheme, host and a port the URL named: never its path, query or credentials. */
    public readonly string $url;

    /** $url is reduced to its label here, so no throw site can carry more of it. */
    public function __construct(
        string $message,
        string $url,
        public readonly int $curlError = 0,
    ) {
        parent::__construct($message);
        $this->url = Url::parse($url)?->label() ?? '[unparseable-url]';
    }
}
