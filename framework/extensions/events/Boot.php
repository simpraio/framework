<?php

declare(strict_types=1);

namespace extensions\events;

use core\extension\Bootable;
use core\extension\Boot as ExtensionBoot;

final class Boot extends ExtensionBoot implements Bootable
{
    public function boot(): void
    {
        Event::init(new EventDispatcher());
    }
}
