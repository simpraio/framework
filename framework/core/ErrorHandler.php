<?php

declare(strict_types=1);

namespace core;

use core\error\Page;
use core\error\SecurityHeaders;
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
     * Record an access-control denial. 4xx responses are otherwise unlogged on purpose - a 404
     * sweep would drown the log - but 401 and 403 are decisions about who may reach what, and a
     * deployment with no record of them cannot answer "was this route ever refused, and to whom".
     *
     * Identity belongs to the auth extension, not core, so only the request coordinates are
     * recorded here; a product that needs the username should log it where it makes the decision.
     * Written at WARNING so it survives the default threshold, and never allowed to throw: a
     * failed audit write must not turn a 403 into a 500.
     */
    private static function logDenial(int $status): void
    {
        if (self::$log === null) {
            return;
        }

        try {
            /** @var mixed $requestUri */
            $requestUri = $_SERVER['REQUEST_URI'] ?? null;
            $path = is_string($requestUri)
                ? parse_url(substr(string: $requestUri, offset: 0, length: 2_048), PHP_URL_PATH)
                : null;

            self::$log->log(Writer::WARNING, "Access denied ({$status})", [
                'method' => substr(string: self::serverValue('REQUEST_METHOD'), offset: 0, length: 16),
                // Query values may contain credentials or PII. The route path is sufficient
                // for access-control auditing and is bounded against log amplification.
                'path' => substr(
                    string: is_string($path) ? $path : '/',
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
