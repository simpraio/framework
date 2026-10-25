<?php

declare(strict_types=1);

namespace extensions\oidc_login;

use core\config\Config as CoreConfig;
use core\extension\Boot as ExtensionBoot;
use core\extension\Contributor;
use core\http\Route;

/**
 * Template blocks are presentation, not authorization.
 * Disabled providers must answer false for both blocks: unanswered blocks render intact.
 */
final class Boot extends ExtensionBoot implements Contributor
{
    public function contribute(Route $route): array
    {
        $config = CoreConfig::extensionConfig(Config::NAME, Config::class);
        if (!$config->enabled) {
            return [];
        }

        $tokens = [];
        $blocks = [];
        foreach ($config->providers as $name => $provider) {
            $authenticated = $provider->enabled && User::isAuthenticated($name);
            if ($authenticated) {
                $tokens[$provider->token('USER_NAME')] = User::name($name);
                $tokens[$provider->token('USER_EMAIL')] = User::email($name);
            }
            $blocks[$provider->block('Authenticated')] = $authenticated;
            $blocks[$provider->block('Guest')] = $provider->enabled && !$authenticated;
        }

        return ['tokens' => $tokens, 'blocks' => $blocks];
    }
}
