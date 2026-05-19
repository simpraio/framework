<?php

declare(strict_types=1);

namespace modules\main;

use core\Controller;
use core\http\Response;
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
                'LOGIN_ERROR' => $hasError,
            ]);
    }

    private function safeReturnTo(): ?string
    {
        $returnTo = Request::input('return') ?? Request::query('return');
        if ($returnTo === null || $returnTo === '') {
            return null;
        }

        if ($returnTo[0] !== '/'
            || str_starts_with($returnTo, '//')
            || str_starts_with($returnTo, '/\\')
            || preg_match('/[\r\n\0]/', $returnTo) === 1
        ) {
            return null;
        }

        return $returnTo;
    }
}
