<?php

declare(strict_types=1);

namespace modules\main;

use core\Controller;
use core\http\Response;
use core\http\Url;
use core\Request;
use core\Template;
use extensions\auth\Auth;

final class Login extends Controller
{
    public function compose(Template $template): Template|Response
    {
        $returnTo = $this->safeReturnTo();

        if (!Request::isMethod('POST')) {
            return $this->form($template, '', $returnTo ?? '', false);
        }

        $username = trim(Request::input('username', '') ?? '');
        $password = Request::input('password', '') ?? '';

        if (Auth::login($username, $password)) {
            return Response::redirect($returnTo ?? '/');
        }

        return $this->form($template, $username, $returnTo ?? '', true);
    }

    private function form(Template $template, string $username, string $returnTo, bool $hasError): Template
    {
        return $template
            ->tokens([
                'USERNAME' => $username,
                'RETURN_TO' => $returnTo,
            ])
            ->blocks([
                'HasLoginError' => $hasError,
            ]);
    }

    private function safeReturnTo(): ?string
    {
        $returnTo = Request::input('return') ?? Request::query('return');

        // The same check Response::redirect() applies: anything it would refuse is dropped here.
        return $returnTo !== null && Url::isLocal($returnTo) ? $returnTo : null;
    }
}
