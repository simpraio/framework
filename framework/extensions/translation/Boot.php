<?php

declare(strict_types=1);

namespace extensions\translation;

use core\config\Config as CoreConfig;
use core\extension\Contributor;
use core\extension\Boot as ExtensionBoot;
use core\http\Route;

/**
 * Translations render as unescaped HTML. Only trusted administrators may edit them.
 */
final class Boot extends ExtensionBoot implements Contributor
{
    public function contribute(Route $route): array
    {
        if (!$this->config()->enabled) {
            return [];
        }

        $layout = Store::tokens(Store::LAYOUT_PATH_ID, $route->language);
        $page = Store::tokens($route->pathId(), $route->language);

        return [
            'rawTokens' => [...$layout, ...$page],
        ];
    }

    private function config(): Config
    {
        return CoreConfig::extensionConfig(Config::NAME, Config::class);
    }
}
