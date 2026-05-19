<?php

declare(strict_types=1);

namespace core;

use RuntimeException;

/**
 * Compiles framework files into single PHP bundles, so a request pays for
 * one require/stat/parse instead of dozens. Bundles are regenerated lazily
 * when missing.
 *
 * Source files are wrapped in bracketed namespace blocks; the result is
 * functionally equivalent to including each file directly.
 *
 * @internal
 */
final class Bundle
{
    public const string EXTENSIONS_FILE = 'extensions.php';
    public const string CONFIG_FILE = 'config.php';

    public static function buildCore(string $coreDir, string $outFile): void
    {
        $manifest = self::read($coreDir . '/bootstrap.php');

        $matches = [];
        if (!preg_match_all(
            '/require_once\s+\$core\s*\.\s*[\'"]([^\'"]+)[\'"]\s*;/',
            $manifest,
            $matches,
        )) {
            return;
        }

        $parts = [];
        foreach ($matches[1] as $rel) {
            $parts[] = self::wrap(self::read($coreDir . $rel));
        }

        self::write($outFile, "<?php\ndeclare(strict_types=1);\n\n" . implode("\n\n", $parts) . "\n");
    }

    /**
     * @param list<array{class: class-string, file: string, config: ?string}> $map
     */
    public static function buildExtensions(array $map, string $outFile): void
    {
        $parts = [];
        foreach ($map as $entry) {
            $parts[] = self::wrap(self::read($entry['file']));

            if ($entry['config'] === null) {
                continue;
            }
            $parts[] = self::wrap(self::read($entry['config']));
        }

        $exported = var_export($map, return: true);
        $body = "<?php\ndeclare(strict_types=1);\n\n"
            . implode("\n\n", $parts)
            . "\n\nnamespace {\n    return {$exported};\n}\n";

        self::write($outFile, $body);
    }

    /** @param array<string, mixed> $defaults */
    public static function buildConfig(array $defaults, string $outFile): void
    {
        $body = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($defaults, return: true) . ";\n";
        self::write($outFile, $body);
    }

    private static function wrap(string $src): string
    {
        $src = preg_replace(pattern: '/^\s*<\?php\s*/', replacement: '', subject: $src, limit: 1) ?? $src;
        $src = preg_replace(
            pattern: '/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*/m',
            replacement: '',
            subject: $src,
            limit: 1,
        ) ?? $src;

        /** @var array{0: array{string, int}, 1: array{string, int}} $m */
        if (preg_match('/^\s*namespace\s+([^;]+);\s*/m', $src, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return "namespace {\n" . trim($src) . "\n}";
        }

        $ns = trim($m[1][0]);
        $body = substr($src, $m[0][1] + strlen($m[0][0]));
        return "namespace {$ns} {\n" . trim($body) . "\n}";
    }

    private static function write(string $outFile, string $body): void
    {
        $pid = getmypid();
        $tmp = $outFile . '.tmp.' . ($pid === false ? '0' : (string)$pid);

        if (self::silent(static fn(): int|false => file_put_contents($tmp, $body, LOCK_EX)) === false) {
            throw new RuntimeException("Bundle: cannot write temporary file: {$tmp}");
        }

        if (!self::silent(static fn(): bool => rename($tmp, $outFile))) {
            self::silent(static fn(): bool => unlink($tmp));
            throw new RuntimeException("Bundle: cannot move bundle into place: {$outFile}");
        }
    }

    private static function read(string $file): string
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException("Bundle: cannot read source file: {$file}");
        }

        $src = self::silent(static fn(): string|false => file_get_contents($file));
        if ($src === false) {
            throw new RuntimeException("Bundle: cannot read source file: {$file}");
        }

        return $src;
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private static function silent(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
