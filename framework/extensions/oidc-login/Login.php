<?php

declare(strict_types=1);

namespace extensions\oidc_login;

use core\http\Url;
use core\http\Request;
use core\http\Response;
use core\Session;
use Exception;
use extensions\oidc_login\oidc\Flow;
use extensions\oidc_login\oidc\Token;
use Throwable;

final class Login
{
    /**
     * $returnTo is where a successful sign-in lands instead of success_redirect - typically the
     * page that sent the user to log in. Only a path on this site is kept; anything else, such
     * as an absolute or protocol-relative URL, is ignored rather than refused. It stays on the
     * server with this sign-in and is never sent to the provider.
     */
    public static function start(string $provider, ?string $returnTo = null): Response
    {
        $config = Config::enabled($provider);
        $target = $returnTo !== null && Url::isLocal($returnTo) ? $returnTo : '';

        return (new Response('', 302))->header('Location', Flow::authorizeUrl($config, Flow::begin($config, $target)));
    }

    /** @param (callable(Throwable): void)|null $onFailure */
    public static function callback(string $provider, Request $request, ?callable $onFailure = null): Response
    {
        $config = Config::enabled($provider);
        try {
            return Response::redirect(self::complete($config, $request));
        } catch (Exception $e) {
            try {
                if ($onFailure !== null) {
                    $onFailure($e);
                }
            } catch (Throwable $ignored) {
                // Failure reporting must not replace the redirect.
                unset($ignored);
            }
            return Response::redirect($config->failureRedirect);
        }
    }

    public static function logout(string $provider, ?string $route = null, bool $destroySession = false): Response
    {
        $config = Config::enabled($provider);
        $path = $route !== null
            ? Url::configuredPath($route, 'INVALID_OIDC_LOGIN_REDIRECT_PATH')
            : $config->successRedirect;
        User::clear($provider);
        self::endSession($config, $destroySession);

        return Response::redirect($path);
    }

    private static function endSession(Provider $config, bool $destroySession): void
    {
        if ($destroySession) {
            Session::destroy();
            return;
        }

        Flow::discard($config);
        Session::regenerate();
    }

    private static function complete(Provider $config, Request $request): string
    {
        $flow = Flow::consume($config, $request->query('state') ?? '');
        $error = $request->query('error');
        if ($error !== null && $error !== '') {
            throw new LoginException($config->reason('PROVIDER_ERROR'));
        }
        $code = $request->query('code') ?? '';
        if ($code === '') {
            throw new LoginException($config->reason('NO_CODE'));
        }

        $tokens = Token::exchange($config, $code, $flow['verifier']);
        $profile = $config->registration->identify(
            $config,
            $tokens['id_token'],
            $flow['nonce'],
            $tokens['access_token'],
        );

        Session::rotate();
        User::set($config->name, [...$profile, 'authenticated_at' => time()]);

        // Checked again here, not only at start: the session is what is read back.
        return $flow['return'] !== '' && Url::isLocal($flow['return'])
            ? $flow['return']
            : $config->successRedirect;
    }
}
