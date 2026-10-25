<?php

declare(strict_types=1);

namespace core;

use core\error\Page;
use core\error\SecurityHeaders;
use core\http\HttpException;
use core\http\Url;
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
        // Traces are logged and shown in debug pages, and call arguments carry request data and
        // credentials; #[SensitiveParameter] covers only the parameters someone remembered to mark.
        ini_set(option: 'zend.exception_ignore_args', value: '1');

        set_error_handler(self::onError(...));
        set_exception_handler(self::onException(...));
        register_shutdown_function(self::onShutdown(...));
    }

    /** Recorded and stepped over; everything else in the mask stops the request. */
    private const int TOLERATED = E_DEPRECATED | E_USER_DEPRECATED;

    /** @throws ErrorException */
    private static function onError(int $severity, string $message, string $file, int $line): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        if (($severity & self::TOLERATED) !== 0) {
            self::$log?->log(Writer::WARNING, 'Deprecated: ' . $message, ['file' => $file, 'line' => $line]);
            return true;
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
        if ($status === 401 || $status === 403) {
            self::logDenial($status);
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, self::page()->text($e) . PHP_EOL);
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=UTF-8');
            SecurityHeaders::emit(self::$debug);
        }

        echo self::page()->html($e, $status);
    }

    /**
     * Audit 401/403 without allowing logging failures to alter the response.
     */
    private static function logDenial(int $status): void
    {
        if (self::$log === null) {
            return;
        }

        try {
            /** @var mixed $requestUri */
            $requestUri = $_SERVER['REQUEST_URI'] ?? null;
            $target = is_string($requestUri) ? substr(string: $requestUri, offset: 0, length: 2_048) : '';
            // A target of any other form, such as http://user:secret@host/x, is logged by its
            // parsed path alone, so credentials in it never reach the log.
            $path = Url::isLocal($target) ? Url::path($target) : (Url::parse($target)->path ?? '/');

            self::$log->log(Writer::WARNING, "Access denied ({$status})", [
                'method' => substr(string: self::serverValue('REQUEST_METHOD'), offset: 0, length: 16),
            // Exclude query credentials and bound the untrusted path against log amplification.
                'path' => substr(
                    string: $path,
                    offset: 0,
                    length: 512,
                ),
                'ip' => substr(string: self::serverValue('REMOTE_ADDR'), offset: 0, length: 45),
            ]);
        } catch (Throwable $writeFailure) {
            error_log('ErrorHandler: denial log write failed: ' . $writeFailure->getMessage());
        }
    }

    private static function serverValue(string $key): string
    {
        /** @var mixed $value */
        $value = $_SERVER[$key] ?? null;
        return is_string($value) ? $value : '';
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

    private static function page(): Page
    {
        return new Page(self::$debug, self::$view);
    }
}
