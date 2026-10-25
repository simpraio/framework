<?php

declare(strict_types=1);

namespace extensions\registry;

use core\config\Config as CoreConfig;
use core\extension\Hook;
use core\extension\Boot as ExtensionBoot;
use core\http\Route;
use core\http\Request;
use core\http\Response;

final class Boot extends ExtensionBoot implements Hook
{
    public function before(Request $request, Route $route): ?Response
    {
        if ($this->config()->enabled) {
            Store::setLanguage($route->language);
        }
        return null;
    }

    public function after(Request $request, Route $route, Response $response): void
    {
    }

    private function config(): Config
    {
        return CoreConfig::extensionConfig(Config::NAME, Config::class);
    }
}
