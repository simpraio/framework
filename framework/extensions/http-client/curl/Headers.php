<?php

declare(strict_types=1);

namespace extensions\http_client\curl;

use core\http\HeaderField;

final class Headers
{
    /** Kept across origins; a denylist of credential names always misses a scheme (x-auth, x-signature). */
    private const array CROSS_ORIGIN = ['accept', 'accept-encoding', 'accept-language', 'user-agent', 'cache-control', 'pragma'];

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
    public static function forOtherOrigin(?array $headers): ?array
    {
        if ($headers === null) {
            return null;
        }

        $kept = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && in_array(strtolower($name), self::CROSS_ORIGIN, strict: true)) {
                $kept[$name] = $value;
            }
        }

        return $kept === [] ? null : $kept;
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

            if (str_starts_with($header, 'HTTP/')) {
                $responseHeaders = [];
                return $length;
            }

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
        if (!is_string($key) || !is_scalar($value)) {
            return false;
        }

        return HeaderField::isName($key) && HeaderField::isValue((string)$value);
    }
}
