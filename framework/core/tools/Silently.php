<?php

declare(strict_types=1);

namespace core\tools;

/**
 * Runs a filesystem or network call whose failure is reported by its return value. The
 * framework's error handler turns warnings into exceptions; this keeps that handler for
 * everything else and always restores it, even when the call throws.
 */
final class Silently
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public static function call(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
