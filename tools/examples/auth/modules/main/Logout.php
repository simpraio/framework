<?php

declare(strict_types=1);

namespace modules\main;

use core\Controller;
use core\http\Response;
use core\Template;
use extensions\auth\Auth;

final class Logout extends Controller
{
    public function compose(Template $template): Response
    {
        return Auth::logout();
    }
}
