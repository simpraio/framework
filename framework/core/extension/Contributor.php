<?php

declare(strict_types=1);

namespace core\extension;

use core\http\Route;

interface Contributor
{
    /**
     * Tokens, raw tokens, and blocks to apply to the final rendered template
     * (layout + page). All keys are optional.
     *
     * @return array{
     *     tokens?: array<string, string>,
     *     rawTokens?: array<string, string>,
     *     blocks?: array<string, bool|string>,
     * }
     */
    public function contribute(Route $route): array;
}
