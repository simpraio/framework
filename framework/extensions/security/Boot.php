<?php

declare(strict_types=1);

namespace extensions\security;

use core\config\Config as CoreConfig;
use core\extension\Boot as ExtensionBoot;
use core\extension\Hook;
use core\http\Request;
use core\http\Response;
use core\http\Route;

final class Boot extends ExtensionBoot implements Hook
{
    private const array HTML_ONLY = [
        'content-security-policy',
        'content-security-policy-report-only',
        'x-frame-options',
        'permissions-policy',
        'cross-origin-opener-policy',
        'cross-origin-embedder-policy',
    ];

    public function before(Request $request, Route $route): ?Response
    {
        return null;
    }

    public function after(Request $request, Route $route, Response $response): void
    {
        $config = $this->config();
        if (!$config->enabled) {
            return;
        }

        $isHtml = str_starts_with(
            $response->getHeader('Content-Type') ?? '',
            'text/html',
        );

        if ($isHtml && Csp::usesNonce($response->body)) {
            $response->body(Csp::injectNonce($response->body));
        }

        foreach ($config->headers as $name => $value) {
            if ($value === '') {
                continue;
            }

            if (strtolower($name) === 'strict-transport-security' && !$request->isSecure()) {
                continue;
            }

            if (!$isHtml && in_array(strtolower($name), self::HTML_ONLY, strict: true)) {
                continue;
            }

            if ($isHtml && Csp::usesNonce($value)) {
                $value = Csp::injectNonce($value);
            }

            $response->header($name, $value);
        }
    }

    private function config(): Config
    {
        return CoreConfig::extensionConfig(Config::NAME, Config::class);
    }
}
