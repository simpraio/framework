<?php

declare(strict_types=1);

namespace extensions\http_client;

use RuntimeException;

final class HttpClientException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $url,
        public readonly int $curlError = 0,
    ) {
        parent::__construct($message);
    }
}
