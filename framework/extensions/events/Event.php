<?php

declare(strict_types=1);

namespace extensions\events;

final class Event
{
    private static EventDispatcher $dispatcher;

    public static function init(EventDispatcher $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
    }

    public static function on(string $event, callable $listener): void
    {
        Config::enabled();

        self::$dispatcher->on($event, $listener);
    }

    public static function dispatch(string $event, mixed ...$args): void
    {
        Config::enabled();

        self::$dispatcher->dispatch($event, ...$args);
    }
}
