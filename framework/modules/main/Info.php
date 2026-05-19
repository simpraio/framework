<?php

declare(strict_types=1);

namespace modules\main;

use core\Controller;
use core\http\Response;
use core\Template;

final class Info extends Controller
{
    public function compose(Template $template): Template|Response
    {
        return $template;
    }
}
