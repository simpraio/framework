<?php

declare(strict_types=1);

namespace core\config;

use Throwable;

/**
 * Redacts DTO secret fields from var_dump() and json_encode().
 *
 * #[SensitiveParameter] protects stack traces, not public properties. Redaction
 * defaults to on and fails closed when config cannot be read.
 *
 * NOT hooked: serialize() (must round-trip, so it keeps the real value), and
 * var_export()/print_r() (no redaction hook exists). Do not pass a secret-bearing
 * config DTO to those when emitting to logs.
 */
trait RedactsSecrets
{
    /**
     * Names of properties whose values must never appear in dumps/JSON.
     *
     * @return list<string>
     */
    abstract protected function secretKeys(): array;

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->safeVars();
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->safeVars();
    }

    /** @return array<string, mixed> */
    private function safeVars(): array
    {
        $vars = Map::stringKeyed(get_object_vars($this));

        if (!self::secretRedactionEnabled()) {
            return $vars;
        }

        foreach ($this->secretKeys() as $key) {
            if (!array_key_exists($key, $vars) || $vars[$key] === '') {
                continue;
            }

            $vars[$key] = '[REDACTED]';
        }

        return $vars;
    }

    private static function secretRedactionEnabled(): bool
    {
        try {
            return Config::log()->redactSecrets;
        } catch (Throwable) {
            return true; // fail-safe: redact when the setting cannot be read
        }
    }
}
