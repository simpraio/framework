<?php

declare(strict_types=1);

namespace extensions\httpclient\curl;

final class Headers
{
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
