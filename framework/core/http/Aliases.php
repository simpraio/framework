<?php

declare(strict_types=1);

namespace core\http;

use core\cache\Optional;
use core\config\Config;
use core\config\dto\Route as RouteConfig;
use core\db\Db;

final readonly class Aliases
{
    public function __construct(
        private RouteConfig $config,
    ) {}

    /** Public canonical URI including language prefix, or null if no alias. */
    public static function uri(Route $route): ?string
    {
        $path = new self(Config::route())->path($route);
        if ($path === null) {
            return null;
        }

        return '/' . ($route->language !== '' ? $route->language . '/' : '') . $path;
    }

    /** @param list<string> $segments */
    public function resolve(string $language, array $segments): ?Route
    {
        if (!$this->config->aliasesEnabled || $segments === []) {
            return null;
        }

        $target = $this->map($language)[implode('/', $segments)] ?? null;
        if ($target === null) {
            return null;
        }

        return new Route(
            language: $language,
            module: $target['module'],
            controller: $target['controller'],
        );
    }

    public function path(Route $route): ?string
    {
        if (!$this->config->aliasesEnabled) {
            return null;
        }

        $pathId = $route->pathId();
        return array_find_key(
            $this->map($route->language),
            static fn($target) => $target['module'] . '/' . $target['controller'] === $pathId
        );
    }

    /**
     * @return array<string, array{module: string, controller: string}>
     */
    private function map(string $language): array
    {
        return Optional::remember(
            'aliases.' . $language,
            fn(): array => $this->fetch($language),
            $this->config->aliasesCacheTtl,
            [],
            'Alias map unavailable; falling back to unaliased routing',
        );
    }

    /**
     * @return array<string, array{module: string, controller: string}>
     */
    private function fetch(string $language): array
    {
        $map = [];

        foreach (Db::select(
            '
            SELECT
                `path`, `module`, `controller`
            FROM
                `aliases`
            WHERE
                `language` = :language
            ORDER BY
                `path`',
            ['language' => $language],
        ) as $row) {
            if (!is_string($row['path'] ?? null)
                || !is_string($row['module'] ?? null)
                || !is_string($row['controller'] ?? null)
            ) {
                continue;
            }

            $path = self::normalizePath($row['path']);
            $module = RouteSegment::normalize($row['module']);
            $controller = RouteSegment::normalize($row['controller']);

            if ($path === null || $module === null || $controller === null) {
                continue;
            }

            $map[$path] = [
                'module' => $module,
                'controller' => $controller,
            ];
        }

        return $map;
    }

    private static function normalizePath(string $path): ?string
    {
        $segments = RouteSegment::split($path);
        if ($segments === null || $segments === []) {
            return null;
        }

        return implode(separator: '/', array: $segments);
    }
}
