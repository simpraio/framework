<?php

declare(strict_types=1);

namespace core\extension;

use core\http\Route;

interface Contributor
{
    /**
     * Applied to the final layout and page together.
     *
     * @return array{
     *     tokens?: array<string, string>,
     *     rawTokens?: array<string, string>,
     *     blocks?: array<string, bool|string>,
     * }
     */
    public function contribute(Route $route): array;
}
