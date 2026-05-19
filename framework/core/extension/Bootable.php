<?php

declare(strict_types=1);

namespace core\extension;

interface Bootable
{
    public function boot(): void;
}
