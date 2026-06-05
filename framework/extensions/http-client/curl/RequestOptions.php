<?php

declare(strict_types=1);

namespace extensions\http_client\curl;

final class RequestOptions
{
    public string $method;
    public mixed $data;
    /** @var array<string, scalar>|null */
    public ?array $headers;
    public ?string $cookies;
    public bool $raw;
    public ?int $timeout;
    public ?int $connectTimeout;

    /** @param array<string, mixed> $options */
    public function __construct(array $options)
    {
        $this->data = $options['data'] ?? null;
        $this->method = Method::normalize($options['method'] ?? (Body::hasData($this->data) ? 'POST' : 'GET'));
        $this->headers = Headers::normalize($options['headers'] ?? null);
        $this->cookies = self::optionalString($options['cookies'] ?? null);
        $this->raw = ($options['raw'] ?? false) === true;
        $this->timeout = self::optionalInt($options['timeout'] ?? null);
        $this->connectTimeout = self::optionalInt($options['connect_timeout'] ?? null);
    }

    public function forRedirect(int $status, bool $sameOrigin = true): self
    {
        $dropBody = $status === 303 || ($this->method === 'POST' && ($status === 301 || $status === 302));

        return new self([
            'method' => $dropBody ? 'GET' : $this->method,
            'data' => $dropBody ? null : $this->data,
            'headers' => $sameOrigin ? $this->headers : Headers::withoutSensitive($this->headers),
            'cookies' => $sameOrigin ? $this->cookies : null,
            'raw' => $dropBody ? false : $this->raw,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ]);
    }

    private static function optionalString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);

        return $value !== '' ? $value : null;
    }

    private static function optionalInt(mixed $value): ?int
    {
        return is_int($value) || is_string($value) ? (int)$value : null;
    }
}
