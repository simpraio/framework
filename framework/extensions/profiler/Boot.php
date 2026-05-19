<?php

declare(strict_types=1);

namespace extensions\profiler;

use core\config\Config as CoreConfig;
use core\extension\Hook;
use core\extension\Boot as ExtensionBoot;
use core\http\Request;
use core\http\Response;
use core\http\Route;

final class Boot extends ExtensionBoot implements Hook
{
    public function before(Request $request, Route $route): ?Response
    {
        return null;
    }

    public function after(Request $request, Route $route, Response $response): void
    {
        if (!$this->config()->enabled) {
            return;
        }

        if (!str_contains($response->getHeader('Content-Type') ?? '', 'text/html')) {
            return;
        }

        $report = Profiler::report();
        if ($report !== '') {
            $response->body($response->body . $report);
        }
    }

    private function config(): Config
    {
        return CoreConfig::extensionConfig(Config::NAME, Config::class);
    }
}
