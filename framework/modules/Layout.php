<?php

declare(strict_types=1);

namespace modules;

use core\tools\Assets;
use core\Controller;
use core\http\Response;
use core\Template;

final class Layout extends Controller
{
    public function compose(Template $template): Template|Response
    {
        $assets = new Assets($this->paths->public);

        return $this->view->load('layout')
            ->tokens([
                'HEAD_TITLE' => 'SIMPRA',
                'HEAD_DESCRIPTION' => 'Minimal PHP 8.4+ framework for small websites, internal tools, and simple SaaS projects.',
                'VERSION' => $assets->version('/assets/css/common.css'),
            ])
            ->rawTokens([
                'MAIN' => $template->render(),
            ]);
    }
}
