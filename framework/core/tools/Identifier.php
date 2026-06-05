<?php

declare(strict_types=1);

namespace core\tools;

use InvalidArgumentException;

final class Identifier
{
    private static int $lastUuidMilliseconds = 0;
    private static int $uuidSequence = 0;

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
     * Generates UUIDv7-shaped IDs ordered within this PHP process.
     *
     * The timestamp prefix is milliseconds. When multiple IDs are created in the
     * same millisecond, or the clock moves backwards, rand_a is used as a
     * per-process counter. If the counter is exhausted, the timestamp advances by 1ms.
     */
    public static function uuid(): string
    {
        $ms = (int)(microtime(true) * 1000);
        if ($ms > self::$lastUuidMilliseconds) {
            self::$uuidSequence = random_int(min: 0, max: 0x0fff);
        }

        if ($ms <= self::$lastUuidMilliseconds) {
            $ms = self::$lastUuidMilliseconds;
            self::$uuidSequence++;

            if (self::$uuidSequence > 0x0fff) {
                $ms = self::$lastUuidMilliseconds + 1;
                self::$uuidSequence = 0;
            }
        }

        self::$lastUuidMilliseconds = $ms;

        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            ($ms >> 16) & 0xffff_ffff,
            $ms & 0xffff,
            0x7000 | self::$uuidSequence,
            0x8000 | random_int(min: 0, max: 0x3fff),
            random_int(min: 0, max: 0xffff_ffff_ffff),
        );
    }
}
