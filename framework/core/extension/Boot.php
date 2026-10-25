<?php

declare(strict_types=1);

namespace core\extension;

abstract class Boot
{
    /**
     * Hook and contributor order: lower runs first, equal priorities run in folder-name order.
     * The first before() hook to return a response ends the round, so a hook that must see every
     * request - a rate limit - runs ahead of hooks that turn requests away.
     */
    public const int PRIORITY = 0;

    final public function __construct()
    {
    }
}
