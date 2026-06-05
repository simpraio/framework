<?php

declare(strict_types=1);

namespace extensions\http_client\curl;

final class Headers
{
    /** @return array<string, scalar>|null */
    public static function normalize(mixed $headers): ?array
    {
        if (!is_array($headers)) {
            return null;
        }

        $valid = [];
        foreach (array_keys($headers) as $key) {
            if (!is_string($key) || !is_scalar($headers[$key])) {
                continue;
            }

            $valid[$key] = $headers[$key];
        }

        return $valid === [] ? null : $valid;
    }

    /**
     * @param array<int|string, scalar>|null $headers
     * @return array<int|string, scalar>|null
     */
    public static function withoutSensitive(?array $headers): ?array
    {
        if ($headers === null) {
            return null;
        }

        $filtered = [];
        foreach ($headers as $key => $value) {
            if (
                is_string($key)
                && in_array(strtolower($key), ['authorization', 'proxy-authorization', 'cookie'], strict: true)
            ) {
                continue;
            }
            $filtered[$key] = $value;
        }

        return $filtered === [] ? null : $filtered;
    }

    /**
     * @param array<string, scalar>|null $headers
     * @return list<string>
     */
    public static function request(?array $headers): array
    {
        if (!is_array($headers) || $headers === []) {
            return [];
        }

        $prepared = [];

        foreach ($headers as $key => $value) {
            if (!self::valid($key, $value)) {
                continue;
            }

            $prepared[] = $key . ': ' . (string)$value;
        }

        return $prepared;
    }

    /** @param array<string, string|list<string>> $responseHeaders */
    public static function response(array &$responseHeaders): callable
    {
        return static function ($curl, string $header) use (&$responseHeaders): int {
            $length = strlen($header);
            $header = trim($header);

            if ($header === '' || !str_contains($header, ':')) {
                return $length;
            }

            [$name, $value] = explode(separator: ':', string: $header, limit: 2);
            $name = strtolower(trim($name));
            $value = trim($value);

            if (!array_key_exists($name, $responseHeaders)) {
                $responseHeaders[$name] = $value;
                return $length;
            }

            $responseHeaders[$name] = is_array($responseHeaders[$name])
                ? [...$responseHeaders[$name], $value]
                : [$responseHeaders[$name], $value];

            return $length;
        };
    }

    private static function valid(mixed $key, mixed $value): bool
    {
        if (!is_string($key) || $key === '' || !is_scalar($value)) {
            return false;
        }

        return preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $key) === 1
            && preg_match('/[\r\n]/', (string)$value) !== 1;
    }
}
