<?php

declare(strict_types=1);

namespace extensions\http_client;

use core\config\Config as CoreConfig;
use core\extension\Boot as ExtensionBoot;
use core\extension\Bootable;
use core\Log;

/**
 * Logs once when the operator deliberately enables insecure TLS mode.
 */
final class Boot extends ExtensionBoot implements Bootable
{
    public function boot(): void
    {
        $config = CoreConfig::extensionConfig(Config::NAME, Config::class);
        if (!$config->enabled) {
            return;
        }

        if (!$config->verifyTls) {
            Log::warning(
                'http-client TLS verification is DISABLED and explicitly acknowledged '
                . '(extensions.http-client.tls_insecure_acknowledged is set): all outbound requests skip '
                . 'peer and host certificate checks and are vulnerable to MITM. This MUST NOT be production.'
            );
        }
    }
}
