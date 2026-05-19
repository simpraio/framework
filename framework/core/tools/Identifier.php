<?php

declare(strict_types=1);

namespace core\tools;

use InvalidArgumentException;

final class Identifier
{
    public static function code(
        int $length = 8,
        string $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ',
        string $prefix = ''
    ): string {
        if ($length <= 0) {
            return $prefix;
        }

        if ($alphabet === '') {
            throw new InvalidArgumentException('EMPTY_ALPHABET');
        }

        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $prefix . $code;
    }

    public static function fastHash(mixed $value): string
    {
        return hash('xxh3', (string)$value);
    }

    /**
     * Generates a UUIDv7-shaped identifier.
     *
     * The 48-bit timestamp prefix uses PHP-process wall-clock time (microtime), so values
     * are k-sortable at millisecond granularity but NOT strictly monotonic: two calls within
     * the same millisecond - whether in one process or across concurrent requests - share the
     * same timestamp prefix and order only by their random tail. Uniqueness is preserved
     * (74 bits of entropy per ms), but consumers must not assume strict ordering finer than 1 ms.
     */
    public static function uuid(): string
    {
        $ms = (int)(microtime(true) * 1000);

        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            ($ms >> 16) & 0xffff_ffff,
            $ms & 0xffff,
            0x7000 | random_int(min: 0, max: 0x0fff),
            0x8000 | random_int(min: 0, max: 0x3fff),
            random_int(min: 0, max: 0xffff_ffff_ffff),
        );
    }
}
