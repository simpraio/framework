<?php

declare(strict_types=1);

namespace extensions\flash;

use core\config\Config as CoreConfig;
use core\extension\Contributor;
use core\extension\Boot as ExtensionBoot;
use core\http\Route;

final class Boot extends ExtensionBoot implements Contributor
{
    public function contribute(Route $route): array
    {
        if (!$this->config()->enabled) {
            return [];
        }

        return [
            'blocks' => [
                'hasErrors' => Flash::hasErrors(),
            ],
        ];
    }

    private function config(): Config
    {
        return CoreConfig::extensionConfig(Config::NAME, Config::class);
    }
}
