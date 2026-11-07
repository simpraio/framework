<?php

declare(strict_types=1);

namespace extensions\http_client;

/** When a call must have finished, on the monotonic clock: a wall-clock change cannot move it. */
final readonly class Deadline
{
    private function __construct(private int $at)
    {
    }

    public static function in(int $seconds): self
    {
        $now = self::now();
        // A timeout past what the clock can hold is the furthest deadline it can.
        $seconds = min($seconds, intdiv(num1: PHP_INT_MAX - $now, num2: 1_000_000_000));

        return new self($now + $seconds * 1_000_000_000);
    }

    /** Zero or less once the deadline has passed. */
    public function remainingMs(): int
    {
        return intdiv(num1: $this->at - self::now(), num2: 1_000_000);
    }

    private static function now(): int
    {
        return (int)hrtime(as_number: true);
    }
}
