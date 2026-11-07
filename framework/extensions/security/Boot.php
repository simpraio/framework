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
    /** Last: the cache policy depends on the final status and on a session any other hook started. */
    public const int PRIORITY = 100;

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

        $type = strtolower(trim(explode(';', $response->getHeader('Content-Type') ?? '', limit: 2)[0]));
        $isHtml = $type === 'text/html';
        // XML - SVG, XHTML and every other +xml type among it - runs script when opened directly, so it
        // needs the headers a page gets.
        $isDocument = $isHtml || $type === 'text/xml' || $type === 'application/xml' || str_ends_with($type, '+xml');

        // A nonce is fresh for every response, so a page that carries one is never the same for two visitors.
        $nonced = $isHtml && (Csp::usesNonce($response->body) || array_any($config->headers, Csp::usesNonce(...)));
        if ($isHtml && Csp::usesNonce($response->body)) {
            $response->body(Csp::injectNonce($response->body));
        }

        $cacheHeaders = [];
        foreach ($config->headers as $name => $value) {
            if ($value === '') {
                continue;
            }

            if (CachePolicy::isCacheHeader($name)) {
                $cacheHeaders[$name] = $value;
                continue;
            }

            if (strtolower($name) === 'strict-transport-security' && !$request->isSecure()) {
                continue;
            }

            if (!$isDocument && in_array(strtolower($name), self::HTML_ONLY, strict: true)) {
                continue;
            }

            // Only an HTML body has its placeholder filled: another document's nonce would match nothing.
            if ($isDocument && Csp::usesNonce($value)) {
                $value = $isHtml ? Csp::injectNonce($value) : Csp::withoutNonce($value);
            }

            $response->header($name, $value);
        }

        CachePolicy::apply($response, $cacheHeaders, $nonced);
    }

    private function config(): Config
    {
        return CoreConfig::extensionConfig(Config::NAME, Config::class);
    }
}
