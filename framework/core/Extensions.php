<?php

declare(strict_types=1);

namespace core;

use core\extension\Boot;
use core\extension\Contributor;
use core\extension\Hook;
use core\extension\Loader;
use core\http\Request;
use core\http\Response;
use core\http\Route;

final class Extensions
{
    /** @var list<Hook> */
    private array $hooks;

    /** @var list<Contributor> */
    private array $contributors;

    public static function load(string $extensionsDir, string $bundlePath): self
    {
        if (is_file($bundlePath)) {
            /** @var list<array{class: class-string<Boot>, file: string, config: ?string}> $map */
            $map = require $bundlePath;
            return new self($map);
        }

        $map = Loader::buildMap($extensionsDir);
        Bundle::buildExtensions($map, $bundlePath);

        return new self($map);
    }

    /**
     * @param list<array{class: class-string<Boot>, file: string, config: ?string}> $map
     */
    private function __construct(array $map)
    {
        $found = Loader::instantiate($map);
        $this->hooks = $found['hooks'];
        $this->contributors = $found['contributors'];
    }

    public function before(Request $request, Route $route): ?Response
    {
        foreach ($this->hooks as $hook) {
            $response = $hook->before($request, $route);
            if ($response !== null) {
                return $response;
            }
        }
        return null;
    }

    public function after(Request $request, Route $route, Response $response): void
    {
        foreach ($this->hooks as $hook) {
            $hook->after($request, $route, $response);
        }
    }

    public function compose(Template $template, Route $route): Template
    {
        foreach ($this->contributors as $contributor) {
            $bag = $contributor->contribute($route);
            $tokens = $bag['tokens'] ?? [];
            $rawTokens = $bag['rawTokens'] ?? [];
            $blocks = $bag['blocks'] ?? [];
            $template
                ->tokens($tokens)
                ->rawTokens($rawTokens)
                ->blocks($blocks);
        }
        return $template;
    }
}
