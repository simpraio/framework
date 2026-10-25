<?php

declare(strict_types=1);

namespace core\tools;

use RuntimeException;

final class Directory
{
    /**
     * Creates the directory, and any missing parent, unless it already exists. A concurrent
     * request creating it first is not a failure.
     *
     * @throws RuntimeException "{$failure}: {$path}" when it does not exist afterwards
     */
    public static function ensure(string $path, int $mode, string $failure): void
    {
        if (is_dir($path) || Silently::call(static fn(): bool => mkdir($path, $mode, recursive: true)) || is_dir($path)) {
            return;
        }

        throw new RuntimeException("{$failure}: {$path}");
    }
}
