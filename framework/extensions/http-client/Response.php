<?php

declare(strict_types=1);

namespace extensions\http_client;

final class Response
{
    private bool $decoded = false;
    private mixed $payload = null;

    /** Decoded on first read: a body can be several times larger as PHP values. */
    public mixed $json {
        get {
            if (!$this->decoded) {
                $this->payload = Payload::json($this->headers, $this->body);
                $this->decoded = true;
            }

            return $this->payload;
        }
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param mixed $json an already decoded payload; null decodes the body when $json is read
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        mixed $json = null,
    ) {
        if ($json !== null) {
            $this->payload = $json;
            $this->decoded = true;
        }
    }
}
