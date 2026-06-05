<?php

declare(strict_types=1);

namespace extensions\error_log;

use core\config\Config as CoreConfig;
use core\extension\Bootable;
use core\extension\Boot as ExtensionBoot;
use core\cache\Cache;
use core\ErrorHandler;
use core\extension\Hook;
use core\http\Request;
use core\http\Response;
use core\http\Route;
use Throwable;

final class Boot extends ExtensionBoot implements Bootable, Hook
{
    public function boot(): void
    {
        if (!$this->config()->enabled) {
            return;
        }

        ErrorHandler::onLog(static function (Throwable $e): void {
            Logger::log($e);
        });
    }

    public function before(Request $request, Route $route): ?Response
    {
        $config = $this->config();
        if (!$config->enabled) {
            return null;
        }

        Cache::once(
            'error-log.purge',
            static fn() => Logger::purge($config->retentionDays),
            86_400
        );

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
