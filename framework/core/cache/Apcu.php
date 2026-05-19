<?php

declare(strict_types=1);

namespace core\cache;

use APCUIterator;
use SensitiveParameter;

/**
 * @internal Cross-request APCu store. Use {@see Cache} as the public facade.
 */
final class Apcu
{
    private ?bool $enabled = null;

    public function __construct(private readonly string $keyspace = '')
    {
    }

    public function enabled(): bool
    {
        return $this->enabled ??= function_exists('apcu_enabled') && apcu_enabled();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->enabled()) {
            return $default;
        }

        $ok = false;
        /** @var mixed $value */
        $value = apcu_fetch($this->keyspace . $key, $ok);

        return $ok ? $value : $default;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->enabled() && apcu_store($this->keyspace . $key, $value, $ttl) === true;
    }

    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->enabled() && apcu_add($this->keyspace . $key, $value, $ttl) === true;
    }

    /**
     * Atomically increment a counter. Creates the key with $step if it does not exist.
     * Returns false when APCu is unavailable.
     */
    public function inc(string $key, int $step = 1, int $ttl = 0): int|false
    {
        if (!$this->enabled()) {
            return false;
        }

        $namespaced = $this->keyspace . $key;
        $initialValue = 0;
        apcu_add($namespaced, $initialValue, $ttl);

        return apcu_inc($namespaced, $step);
    }

    public function delete(string $key): void
    {
        if (!$this->enabled()) {
            return;
        }

        apcu_delete($this->keyspace . $key);
    }

    public function deletePrefix(string $prefix): void
    {
        if (!$this->enabled()) {
            return;
        }

        apcu_delete(new APCUIterator('#^' . preg_quote($this->keyspace . $prefix, delimiter: '#') . '#'));
    }

    public function once(string $key, callable $callback, int $ttl, float $waitSeconds = 0.0): bool
    {
        if (!$this->enabled()) {
            $callback();
            return true;
        }

        $deadline = microtime(true) + max(0.0, $waitSeconds);

        do {
            $token = bin2hex(random_bytes(16));

            if ($this->add($key, $token, $ttl)) {
                try {
                    $callback();
                } finally {
                    $this->release($key, $token);
                }

                return true;
            }

            if ($waitSeconds <= 0.0) {
                return false;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function release(string $key, #[SensitiveParameter] string $token): void
    {
        /** @var mixed $value */
        $value = $this->get($key);

        if (is_string($value) && hash_equals($token, $value)) {
            $this->delete($key);
        }
    }
}
