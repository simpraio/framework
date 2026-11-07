<?php

declare(strict_types=1);

namespace core\error;

use core\config\Config;
use core\http\HeaderField;
use core\http\Response;
use Throwable;

/**
 * Best-effort headers for HTML errors that bypass the normal extension pipeline.
 */
final class SecurityHeaders
{
    public static function emit(bool $debug, int $status): void
    {
        // A cache header the request sent with header() before failing is its own policy, which the
        // configured one must not replace.
        self::configured($debug, ownPolicy: array_any(
            headers_list(),
            static fn(string $line): bool => self::isCacheHeader(trim(explode(':', $line, limit: 2)[0])),
        ));

        // The list may carry a cache policy meant for pages that are the same for everyone, and a
        // session's own cache headers are not the error page's to keep either.
        if (Response::isUncacheableError($status) || Response::isVisitorBound()) {
            foreach (Response::SHARED_CACHE_HEADERS as $name) {
                header_remove($name);
            }
            header('Cache-Control: no-store');
        }
    }

    private static function isCacheHeader(string $name): bool
    {
        return in_array(strtolower($name), Response::SHARED_CACHE_HEADERS, strict: true);
    }

    private static function configured(bool $debug, bool $ownPolicy): void
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
                if ($ownPolicy && is_string($name) && self::isCacheHeader($name)) {
                    continue;
                }
                self::emitOne($name, $headers[$name]);
            }
        } catch (Throwable $headerFailure) {
            if ($debug) {
                error_log('ErrorHandler: security headers skipped: ' . $headerFailure->getMessage());
            }
        }
    }

    private static function emitOne(int|string $name, mixed $value): void
    {
        if (!is_string($name) || !is_string($value) || $value === '') {
            return;
        }
        if (!HeaderField::isName($name) || !HeaderField::isValue($value)) {
            return;
        }

        // Error rendering cannot populate nonces; drop unresolved nonce source expressions.
        $value = trim((string)preg_replace(
            pattern: '~\s*\'nonce-\{[^}]*\}\'~',
            replacement: '',
            subject: $value,
        ));
        if ($value === '') {
            return;
        }

        header($name . ': ' . $value, replace: true);
    }
}
