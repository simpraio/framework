<?php

declare(strict_types=1);

namespace extensions\http_client;

final readonly class Response
{
    /** @param array<string, string|list<string>> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public mixed $json,
    ) {}
}
