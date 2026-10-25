<?php

declare(strict_types=1);

namespace core\error;

use core\config\Config;
use core\http\HeaderField;
use Throwable;

/**
 * Best-effort headers for HTML errors that bypass the normal extension pipeline.
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
