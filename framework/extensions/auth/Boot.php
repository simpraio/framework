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
            return $config->defaultPolicy === 'deny' ? $this->deny($config) : null;
        }

        return Access::allowedPath($pathId) ? null : $this->deny($config);
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
            'blocks' => [
                'IsAuthenticated' => User::isAuthenticated(),
                'IsGuest' => User::isGuest(),
                ...Access::pathBlocks($route->pathId()),
            ],
        ];
    }

    private function deny(Config $config): Response
    {
        $target = User::isGuest() ? $config->guestRoute : $config->deniedRedirect;

        if ($target !== '/') {
            return Response::redirect($target);
        }

        throw new HttpException(403);
    }

    private function config(): Config
    {
        return CoreConfig::extensionConfig(Config::NAME, Config::class);
    }
}
