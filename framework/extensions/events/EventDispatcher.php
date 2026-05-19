<?php

declare(strict_types=1);

namespace extensions\events;

final class EventDispatcher
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(string $event, mixed ...$args): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener(...$args);
        }
    }
}
