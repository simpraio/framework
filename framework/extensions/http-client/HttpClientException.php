<?php

declare(strict_types=1);

namespace extensions\http_client;

use core\http\Url;
use RuntimeException;
use Throwable;

final class HttpClientException extends RuntimeException
{
    /** Scheme, host and a port the URL named: never its path, query or credentials. */
    public readonly string $url;

    /**
     * $url is reduced to its label here, so no throw site can carry more of it.
     * $sent is false only when nothing of the request was written; true means it may have reached the server.
     */
    public function __construct(
        string $message,
        string $url,
        public readonly int $curlError = 0,
        public readonly bool $sent = true,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
        $this->url = Url::parse($url)?->label() ?? '[unparseable-url]';
    }
}
