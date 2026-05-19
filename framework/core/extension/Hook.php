<?php

declare(strict_types=1);

namespace core\extension;

use core\http\Request;
use core\http\Response;
use core\http\Route;

interface Hook
{
    /** Return a Response to short-circuit the request, or null to continue. */
    public function before(Request $request, Route $route): ?Response;

    public function after(Request $request, Route $route, Response $response): void;
}
