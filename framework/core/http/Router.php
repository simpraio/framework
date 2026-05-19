<?php

declare(strict_types=1);

namespace core\http;

use core\config\dto\Language;
use core\config\dto\Route as RouteConfig;

final readonly class Router
{
    public function __construct(
        private RouteConfig $route,
        private Language $language,
        private Aliases $aliases,
    ) {}

    public function resolve(Request $request): ?Route
    {
        $segments = RouteSegment::split($request->path());
        if ($segments === null) {
            return null;
        }

        $language = $this->language->default;
        if ($this->language->available !== []
            && $segments !== []
            && in_array(needle: $segments[0], haystack: $this->language->available, strict: true)
        ) {
            $language = array_shift($segments);
        }

        $aliased = $this->aliases->resolve($language, $segments);
        if ($aliased !== null) {
            return $aliased;
        }

        return match (count($segments)) {
            0 => new Route($language, $this->route->defaultModule, $this->route->defaultController),
            1 => new Route($language, $this->route->defaultModule, $segments[0]),
            2 => new Route($language, $segments[0], $segments[1]),
            3 => new Route($language, $segments[0], $segments[1], $segments[2]),
            default => null,
        };
    }
}
