<?php

declare(strict_types=1);

namespace core\log;

use core\cache\Cache;
use core\tools\Format;
use RuntimeException;

final class Writer
{
    public const string DEBUG = 'debug';
    public const string INFO = 'info';
    public const string WARNING = 'warning';
    public const string ERROR = 'error';

    private const array LEVELS = [
        self::DEBUG => 0,
        self::INFO => 1,
        self::WARNING => 2,
        self::ERROR => 3,
    ];

    /** @param list<string> $redactKeys lowercased context keys to scrub */
    public function __construct(
        private readonly string $directory,
        public string $level = self::WARNING,
        public bool $rotateDaily = true,
        public int $retentionDays = 14,
        public array $redactKeys = [],
    ) {
        if (!is_dir($this->directory) && !mkdir(
                directory: $this->directory,
                permissions: 0o750,
                recursive: true,
            ) && !is_dir($this->directory)) {
            throw new RuntimeException("Logger: cannot create directory: {$this->directory}");
        }
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (self::LEVELS[$level] < self::LEVELS[$this->level]) {
            return;
        }

        $suffix = '';
        if ($context !== []) {
            $context = $this->redactKeys === [] ? $context : self::scrub($context, $this->redactKeys);
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $suffix = ' ' . ($encoded !== false ? $encoded : '{}');
        }

        $line = sprintf(
            "[%s] [%s] %s%s\n",
            Format::datetime(),
            strtoupper($level),
            str_replace(search: ["\r\n", "\r", "\n"], replace: ' ', subject: $message),
            $suffix,
        );

        $filename = $this->rotateDaily
            ? $this->directory . '/' . Format::datetime(format: 'Y-m-d') . '.log'
            : $this->directory . '/app.log';

        file_put_contents($filename, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Recursively replace values whose keys match $needles (lowercased) with
     * [REDACTED]. Only string keys participate - list items keep their values.
     *
     * @param array<int|string, mixed> $value
     * @param list<string> $needles already-lowercased
     * @return array<int|string, mixed>
     */
    private static function scrub(array $value, array $needles): array
    {
        foreach (array_keys($value) as $key) {
            if (is_string($key) && in_array(strtolower($key), $needles, strict: true)) {
                $value[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value[$key])) {
                $value[$key] = self::scrub($value[$key], $needles);
            }
        }
        return $value;
    }

    /** Delete log files older than retentionDays. Runs at most once per day. */
    public function prune(): void
    {
        if ($this->retentionDays <= 0) {
            return;
        }

        Cache::once('log.prune:' . $this->directory, function (): void {
            $cutoff = time() - ($this->retentionDays * 86_400);
            $files = glob($this->directory . '/*.log');
            if ($files === false) {
                return;
            }
            foreach ($files as $file) {
                $mtime = filemtime($file);
                if ($mtime !== false && $mtime < $cutoff) {
                    unlink($file);
                }
            }
        }, 86_400);
    }
}
