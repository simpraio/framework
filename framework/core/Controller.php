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
     * Build the controller's output.
     *
     * @param Template $template For page controllers: the page's own template
     *                           (templates/{module}/{controller}.html).
     *                           For modules\Layout: the rendered page Template
     *                           to embed inside the layout.
     * @return Template|Response Template - passed to the layout wrapper (or sent as-is
     *                           if modules\Layout doesn't exist).
     *                           Response - sent directly to the client; layout skipped.
     *                           Use Response for redirects, JSON, file downloads, or any
     *                           non-HTML output.
     */
    abstract public function compose(Template $template): Template|Response;
}
