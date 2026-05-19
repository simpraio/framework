<?php

declare(strict_types=1);

namespace core;

use core\log\Writer;

final class Log
{
    private static Writer $writer;

    public static function init(Writer $writer): void
    {
        self::$writer = $writer;
    }

    public static function setLevel(string $level): void
    {
        self::$writer->level = $level;
    }

    /** @param array<string, mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        self::$writer->log(Writer::DEBUG, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::$writer->log(Writer::INFO, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::$writer->log(Writer::WARNING, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::$writer->log(Writer::ERROR, $message, $context);
    }
}
