<?php

declare(strict_types=1);

namespace extensions\httpclient\curl;

final class RequestOptions
{
    public string $method;
    public mixed $data;
    /** @var array<string, scalar>|null */
    public ?array $headers;
    public ?string $cookies;
    public bool $raw;
    public ?string $proxy;
    public ?int $timeout;
    public ?int $connectTimeout;

    /** @param array<string, mixed> $options */
    public function __construct(array $options)
    {
        $this->data = $options['data'] ?? null;
        $this->method = Method::normalize($options['method'] ?? (Body::hasData($this->data) ? 'POST' : 'GET'));
        /** @var array<string, scalar>|null $headers */
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : null;
        $this->headers = $headers;
        $this->cookies = self::optionalString($options['cookies'] ?? null);
        $this->raw = ($options['raw'] ?? false) === true;
        $this->proxy = self::optionalString($options['proxy'] ?? null);
        $this->timeout = self::optionalInt($options['timeout'] ?? null);
        $this->connectTimeout = self::optionalInt($options['connect_timeout'] ?? null);
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
