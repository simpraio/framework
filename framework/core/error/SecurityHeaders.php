<?php

declare(strict_types=1);

namespace core\error;

use core\config\Config;
use Throwable;

/**
 * Applies the same security headers the security extension would, for error pages that
 * bypass the normal Response/Extensions pipeline. Reads the security config directly and
 * falls back silently if the extension is disabled, missing, or config isn't initialized
 * yet (very early errors).
 *
 * Error pages are always HTML, so no content-type filtering is needed - every header in
 * the security config applies.
 */
final class SecurityHeaders
{
    public static function emit(bool $debug): void
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
        if (preg_match('/[\r\n\0]/', $name . $value) === 1) {
            return;
        }

        // Error pages bypass the Response/Extensions pipeline, so a per-request placeholder an
        // extension would normally substitute (a CSP nonce) is still unfilled here. Publishing
        // `'nonce-{...}'` verbatim would advertise a fixed, source-visible nonce, so the whole
        // source expression is dropped instead - never the directive. What remains is the same
        // policy without the nonce, which is stricter.
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
