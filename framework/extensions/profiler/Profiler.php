<?php

declare(strict_types=1);

namespace extensions\profiler;

final class Profiler
{
    /** @var list<array{0: string, 1: float}> */
    private static array $marks = [];

    public static function mark(string $label): void
    {
        Config::enabled();

        self::$marks[] = [$label, microtime(true)];
    }

    public static function report(): string
    {
        Config::enabled();

        if (self::$marks === []) {
            return '';
        }

        $requestStart = $_SERVER['REQUEST_TIME_FLOAT'] ?? self::$marks[0][1];
        $prev = $requestStart;
        $lines = [];

        foreach (self::$marks as [$label, $t]) {
            $fromStart = ($t - $requestStart) * 1_000;
            $fromPrev  = ($t - $prev) * 1_000;
            $lines[] = sprintf('  %-42s %8.3f ms  (+%7.3f ms)', $label, $fromStart, $fromPrev);
            $prev = $t;
        }

        $total = ($prev - $requestStart) * 1_000;
        $lines[] = sprintf('  %-42s %8.3f ms', 'TOTAL', $total);

        return "\n<!--\n[Profiler]\n" . implode("\n", $lines) . "\n-->\n";
    }
}
