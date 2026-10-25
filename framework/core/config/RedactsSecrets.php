<?php

declare(strict_types=1);

namespace core\config;

use Throwable;

/**
 * Protects dumps/JSON only, not serialize(), var_export(), or print_r().
 * Redacts by default when configuration is unavailable.
 */
trait RedactsSecrets
{
    /**
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
            return true;
        }
    }
}
