<?php

declare(strict_types=1);

namespace core;

use core\cache\Cache;
use InvalidArgumentException;

final readonly class View
{
    private const string NAME_REGEX = '#^[a-z0-9_][a-z0-9_\-]*(?:/[a-z0-9_][a-z0-9_\-]*)*$#';
    private const int CACHE_TTL = 60;

    public function __construct(
        private string $templatesDir,
        private bool $debug = false,
    ) {}

    public function exists(string $name): bool
    {
        return $this->isSafe($name) && is_file($this->path($name));
    }

    public function load(string $name): Template
    {
        if (!$this->isSafe($name)) {
            throw new InvalidArgumentException("Invalid template name: {$name}");
        }
        $path = $this->path($name);

        if ($this->debug) {
            return new Template((string) file_get_contents($path));
        }

        $content = Cache::remember(
            'tpl.' . $name,
            static fn(): string => (string) file_get_contents($path),
            self::CACHE_TTL,
        );
        return new Template($content);
    }

    private function isSafe(string $name): bool
    {
        return preg_match(self::NAME_REGEX, $name) === 1;
    }

    private function path(string $name): string
    {
        return "{$this->templatesDir}/{$name}.html";
    }
}
