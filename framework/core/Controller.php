<?php

declare(strict_types=1);

namespace core;

use core\http\Response;
use core\http\Request;
use core\http\Route;

abstract class Controller
{
    final public function __construct(
        protected readonly View $view,
        protected readonly Paths $paths,
        protected readonly Route $route,
        protected readonly Request $request,
    ) {}

    /**
     * Layout receives the rendered page; other controllers receive their own template.
     * Returning a Response bypasses layout rendering.
     */
    abstract public function compose(Template $template): Template|Response;
}
