<?php

declare(strict_types=1);

namespace core;

use core\config\Config;
use core\http\HttpException;
use core\log\Writer;
use ErrorException;
use Throwable;

final class ErrorHandler
{
    private static bool $debug = true;
    private static ?Writer $log = null;
    private static ?View $view = null;
    private static bool $rendering = false;

    /** @var list<callable(Throwable): void> */
    private static array $sinks = [];

    /** Register an additional error sink. File logging remains the primary path; sinks are best-effort. */
    public static function onLog(callable $sink): void
    {
        self::$sinks[] = $sink;
    }

    public static function register(bool $debug, Writer $log, View $view): void
    {
        self::$debug = $debug;
        self::$log = $log;
        self::$view = $view;

        error_reporting(E_ALL);
        ini_set(option: 'display_errors', value: '0');

        set_error_handler(self::onError(...));
        set_exception_handler(self::onException(...));
        register_shutdown_function(self::onShutdown(...));
    }

    /** @throws ErrorException */
    private static function onError(int $severity, string $message, string $file, int $line): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    private static function onException(Throwable $e): void
    {
        if (self::$rendering) {
            error_log('ErrorHandler re-entry: ' . self::describe($e));
            return;
        }

        self::$rendering = true;
        try {
            self::respond($e, self::statusOf($e));
        } finally {
            self::$rendering = false;
        }
    }

    private static function onShutdown(): void
    {
        $err = error_get_last();
        if ($err === null || !self::isFatal($err['type'])) {
            return;
        }

        self::onException(
            new ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line'])
        );
    }

    private static function respond(Throwable $e, int $status): void
    {
        if ($status >= 500) {
            self::log($e);
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, self::page()->text($e) . PHP_EOL);
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=UTF-8');
            self::emitSecurityHeaders();
        }

        echo self::page()->html($e, $status);
    }

    /**
     * Apply the same security headers the security extension would, since
     * error pages bypass the normal Response/Extensions pipeline. Reads the
     * security config directly. Falls back silently if the extension is
     * disabled, missing, or config isn't initialized yet (very early errors).
     *
     * Error pages are always HTML, so no content-type filtering is needed -
     * every header in the security config applies.
     */
    private static function emitSecurityHeaders(): void
    {
        try {
            $raw = Config::extension('security');

            /** @var mixed $enabled */
            $enabled = $raw['enabled'] ?? true;
            if (!filter_var($enabled, FILTER_VALIDATE_BOOL)) {
                return;
            }

            /** @var mixed $headers */
            $headers = $raw['headers'] ?? [];
            if (!is_array($headers)) {
                return;
            }

            foreach (array_keys($headers) as $name) {
                self::emitSecurityHeader($name, $headers[$name]);
            }
        } catch (Throwable $headerFailure) {
            if (self::$debug) {
                error_log('ErrorHandler: security headers skipped: ' . $headerFailure->getMessage());
            }
        }
    }

    private static function emitSecurityHeader(int|string $name, mixed $value): void
    {
        if (!is_string($name) || !is_string($value) || $value === '') {
            return;
        }
        if (preg_match('/[\r\n\0]/', $name . $value) === 1) {
            return;
        }
        header($name . ': ' . $value, replace: true);
    }

    private static function statusOf(Throwable $e): int
    {
        return $e instanceof HttpException ? $e->status : 500;
    }

    private static function isFatal(int $type): bool
    {
        return ($type & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) !== 0;
    }

    public static function describe(Throwable $e): string
    {
        return $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    }

    private static function log(Throwable $e): void
    {
        if (self::$log === null) {
            return;
        }

        try {
            self::$log->log(Writer::ERROR, $e::class . ': ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        } catch (Throwable $writeFailure) {
            error_log('ErrorHandler: log write failed: ' . $writeFailure->getMessage());
            error_log(self::describe($e));
        }

        foreach (self::$sinks as $sink) {
            try {
                $sink($e);
            } catch (Throwable $sinkFailure) {
                error_log('ErrorHandler: sink failed: ' . $sinkFailure->getMessage());
            }
        }
    }

    private static function page(): ErrorPage
    {
        return new ErrorPage(self::$debug, self::$view);
    }
}
