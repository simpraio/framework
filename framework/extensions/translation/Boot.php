<?php

declare(strict_types=1);

namespace extensions\translation;

use core\config\Config as CoreConfig;
use core\extension\Contributor;
use core\extension\Boot as ExtensionBoot;
use core\http\Route;

/**
 * SECURITY: translations are emitted as `rawTokens` (no HTML escaping) so
 * editors can include markup. The `translation.text` column is therefore
 * trusted content - it MUST only be writable by administrators. Never
 * expose translation editing to untrusted users (e.g. external translators)
 * without first sanitising input or splitting plain vs. HTML translations
 * into separate token bags.
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
