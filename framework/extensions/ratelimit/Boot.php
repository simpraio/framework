<?php

declare(strict_types=1);

namespace extensions\ratelimit;

use core\config\Config as CoreConfig;
use core\extension\Boot as ExtensionBoot;
use core\extension\Hook;
use core\http\Request;
use core\http\Response;
use core\http\Route;

final class Boot extends ExtensionBoot implements Hook
{
    public function before(Request $request, Route $route): ?Response
    {
        $config = $this->config();
        if (!$config->enabled) {
            return null;
        }

        if (Limiter::exceeded($request->ip(), $config->max, $config->window)) {
            return Response::text('Too Many Requests', 429);
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
