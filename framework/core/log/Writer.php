<?php

declare(strict_types=1);

namespace core\log;

use Closure;
use core\cache\Cache;
use core\tools\Directory;
use core\tools\Format;
use core\tools\Silently;
use Throwable;

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

    private ?Secrets $knownSecrets = null;
    private ?Context $context = null;
    private bool $secretCollectionFailed = false;

    /**
     * @param list<string> $redactKeys context keys to scrub besides SensitiveKeys' built-in ones
     * @param (Closure(): list<string>)|null $secrets the values to strip from the message and every
     *     context string. Called once, when the first line is written: collecting them walks the whole
     *     configuration, a cost most requests, writing no line, would pay for nothing.
     */
    public function __construct(
        private readonly string $directory,
        public string $level = self::WARNING,
        public readonly bool $rotateDaily = true,
        public readonly int $retentionDays = 14,
        public readonly array $redactKeys = [],
        private readonly ?Closure $secrets = null,
    ) {
        Directory::ensure($this->directory, 0o750, 'Logger: cannot create directory');
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        // Invalid public-API levels must not break logging or silently discard the message.
        $severity = self::LEVELS[$level] ?? self::LEVELS[self::ERROR];
        if ($severity < (self::LEVELS[$this->level] ?? self::LEVELS[self::WARNING])) {
            return;
        }

        if ($this->knownSecrets === null) {
            try {
                $this->knownSecrets = new Secrets($this->secrets !== null ? ($this->secrets)() : []);
            } catch (Throwable) {
                // An incomplete scan cannot safely redact caller text, including an exception's message.
                $this->secretCollectionFailed = true;
                $this->knownSecrets = new Secrets([]);
            }
        }
        if ($this->secretCollectionFailed) {
            $message = Secrets::UNCOLLECTED;
            $context = [];
        }
        $this->context ??= new Context($this->redactKeys, $this->knownSecrets);

        $suffix = $context !== [] ? ' ' . $this->context->encode($context) : '';

        $line = Text::escape(sprintf(
            '[%s] [%s] %s%s',
            Format::datetime(),
            strtoupper($level),
            Text::cap($this->knownSecrets->strip($message)),
            $suffix,
        )) . "\n";

        $filename = $this->rotateDaily
            ? $this->directory . '/' . Format::datetime(format: 'Y-m-d') . '.log'
            : $this->directory . '/app.log';

        if (!is_file($filename)) {
            self::create($filename);
        }
        // Logging must never fail the caller: under the error handler a refused write would be an
        // exception thrown from Log::info(). The line goes to PHP's own error log instead.
        if (Silently::call(static fn(): int|false => file_put_contents($filename, $line, FILE_APPEND | LOCK_EX)) === false) {
            error_log('Logger: cannot write ' . $filename . ': ' . rtrim($line, characters: "\n"));
        }
    }

    /**
     * Log lines can carry personal data, and a file's permissions are checked only when it is opened: a
     * new log is made under a name nobody else knows, owner-only, given its mode, and only then linked
     * into place. If another request linked it first, that one stands. Where that cannot be done - no
     * hard links, or no temporary file beside it - the file is created under its name and narrowed
     * straight after.
     */
    private static function create(string $filename): void
    {
        $directory = dirname($filename);
        $temporary = Silently::call(static fn(): string|false => tempnam($directory, prefix: '.log-'));
        // tempnam() falls back to the system temporary directory, from which no link reaches here.
        if ($temporary === false || realpath(dirname($temporary)) !== realpath($directory)) {
            if ($temporary !== false) {
                Silently::call(static fn(): bool => unlink($temporary));
            }
            Silently::call(static fn(): bool => touch($filename));
            self::narrow($filename, $filename);
            return;
        }

        self::narrow($temporary, $temporary . '.mode');
        $linked = Silently::call(static fn(): bool => link($temporary, $filename));
        Silently::call(static fn(): bool => unlink($temporary));
        if (!$linked && !is_file($filename)) {
            Silently::call(static fn(): bool => touch($filename));
            self::narrow($filename, $filename);
        }
    }

    /**
     * Closes $file to other accounts. The group keeps what the umask gives a new file, so a web server
     * and CLI jobs sharing a group can append; that mode is read back from $created, a file just made -
     * asking umask() for the mask sets it for a moment, and another thread of a threaded server would
     * create its files under that mask. A separate probe is created and removed when $created is not $file.
     */
    private static function narrow(string $file, string $created): void
    {
        if ($created !== $file) {
            Silently::call(static fn(): bool => touch($created));
        }
        clearstatcache(true, $created);
        $mode = Silently::call(static fn(): int|false => fileperms($created));
        if ($created !== $file) {
            Silently::call(static fn(): bool => unlink($created));
        }

        Silently::call(static fn(): bool => chmod($file, permissions: ($mode === false ? 0o600 : $mode) & 0o660));
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
