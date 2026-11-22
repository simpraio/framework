<?php

declare(strict_types=1);

namespace extensions\auth;

use core\config\Config as CoreConfig;
use core\extension\Contributor;
use core\extension\Boot as ExtensionBoot;
use core\extension\Hook;
use core\http\HttpException;
use core\http\Request;
use core\http\Response;
use core\http\Route;

final class Boot extends ExtensionBoot implements Hook, Contributor
{
    public function before(Request $request, Route $route): ?Response
    {
        $config = $this->config();
        if (!$config->enabled) {
            return null;
        }

        $pathId = $route->pathId();

        if (!Access::isControlled($pathId)) {
            return $config->defaultPolicy === 'deny' ? $this->deny($config, $request) : null;
        }

        return Access::allowedPath($pathId) ? null : $this->deny($config, $request);
    }

    public function after(Request $request, Route $route, Response $response): void
    {
    }

    public function contribute(Route $route): array
    {
        if (!$this->config()->enabled) {
            return [];
        }

        return [
            'tokens' => array_filter([
                'USER_ID' => User::isAuthenticated() ? (string)User::id() : null,
                'USER_GROUP' => User::group(),
            ]),
            // array_replace, not a spread: a numeric block name keeps its key, and a configured
            // block named like these two cannot override them.
            'blocks' => array_replace(Access::pathBlocks($route->pathId()), [
                'IsAuthenticated' => User::isAuthenticated(),
                'IsGuest' => User::isGuest(),
            ]),
        ];
    }

    private function deny(Config $config, Request $request): Response
    {
        // Reauthentication cannot grant missing permissions; keep authenticated denials out of the sign-in loop.
        if (!User::isGuest()) {
            if ($config->deniedRedirect === '/') {
                throw new HttpException(403);
            }

            return Response::redirect($config->deniedRedirect)->header('Cache-Control', 'no-store');
        }

        if ($config->guestRoute === '/') {
            throw new HttpException(403);
        }

        return Response::redirect(
            GuestReturn::append($config->guestRoute, $request->uri(), $config->returnParam, $request->rawMethod()),
        )->header('Cache-Control', 'no-store');
    }

    private function config(): Config
    {
        return CoreConfig::extensionConfig(Config::NAME, Config::class);
    }
}
