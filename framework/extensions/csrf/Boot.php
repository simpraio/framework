<?php

declare(strict_types=1);

namespace extensions\csrf;

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
        if (!$this->config()->enabled) {
            return null;
        }

        if (!Guard::requiresCheck($request)) {
            return null;
        }
        if (Guard::valid($request)) {
            return null;
        }
        return Response::text('CSRF token invalid', 419);
    }

    public function after(Request $request, Route $route, Response $response): void
    {
        if (!$this->config()->enabled) {
            return;
        }

        if (!str_starts_with($response->getHeader('Content-Type') ?? '', 'text/html')) {
            return;
        }
        $placeholder = '{' . Guard::FIELD . '}';
        if (str_contains($response->body, $placeholder)) {
            $response->body(str_replace($placeholder, Guard::token(), $response->body));
        }
    }

    private function config(): Config
    {
        return CoreConfig::extensionConfig(Config::NAME, Config::class);
    }
}
