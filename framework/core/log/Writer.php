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
     * @param (Closure(): list<string>)|null $secrets lazily collected on the first written line
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
        // A refused write must reach the fallback, not become an ErrorException in the caller.
        if (Silently::call(static fn(): int|false => file_put_contents($filename, $line, FILE_APPEND | LOCK_EX)) === false) {
            error_log('Logger: cannot write ' . $filename . ': ' . rtrim($line, characters: "\n"));
        }
    }

    /**
     * Set permissions before publishing the log's name to close the permissive-creation window.
     * Without temporary files or hard links, narrow permissions immediately after creation instead.
     * link(), not rename(): a log another request created first stands.
     */
    private static function create(string $filename): void
    {
        $directory = dirname($filename);
        $temporary = Silently::call(static fn(): string|false => tempnam($directory, prefix: '.log-'));
        // tempnam() may fall back outside this directory, where a same-filesystem link is not assured.
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
     * Keep umask-granted group access for web/CLI sharing, but remove all other-account access.
     * Read a newly created file's mode: querying umask() briefly changes process-wide state on threaded servers.
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

    public function prune(): void
    {
        if ($this->retentionDays <= 0) {
            return;
        }

        Cache::once('log.prune:' . $this->directory, function (): void {
            $cutoff = time() - ($this->retentionDays * 86_400);
            // scandir, not glob: [ ] * ? in the install path are ordinary characters.
            $names = Silently::call(fn(): array|false => scandir($this->directory));
            if ($names === false) {
                return;
            }
            $logs = array_filter($names, static fn(string $name): bool => preg_match('/^[^.].*\.log$/Ds', $name) === 1);
            foreach ($logs as $name) {
                $file = $this->directory . '/' . $name;
                $mtime = filemtime($file);
                if ($mtime !== false && $mtime < $cutoff && is_file($file)) {
                    unlink($file);
                }
            }
        }, 86_400);
    }
}
