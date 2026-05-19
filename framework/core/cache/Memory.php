<?php

declare(strict_types=1);

namespace core\cache;

/**
 * @internal Per-request in-memory store. Use {@see Cache} as the public facade.
 */
final class Memory
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }

    public function set(string $key, mixed $value): void
    {
        $this->store[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }

    public function deletePrefix(string $prefix): void
    {
        foreach ($this->store as $key => $_) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            unset($this->store[$key]);
        }
    }

    public function clear(): void
    {
        $this->store = [];
    }
}
