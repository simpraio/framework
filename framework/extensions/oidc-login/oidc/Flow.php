<?php

declare(strict_types=1);

namespace extensions\oidc_login\oidc;

use core\Session;
use extensions\oidc_login\LoginException;
use extensions\oidc_login\Provider;

final class Flow
{
    private const int MAX_PENDING = 5;

    /**
     * @param string $returnTo a local path to send the user to after this sign-in, or '' for none
     * @return array{state: string, nonce: string, challenge: string}
     */
    public static function begin(Provider $config, string $returnTo = ''): array
    {
        $state = Encoding::random();
        $nonce = Encoding::random();
        $verifier = Encoding::random(64);
        $now = time();
        $pending = array_values(array_filter(
            self::stored($config),
            static fn(array $attempt): bool => !self::expired($attempt, $config, $now),
        ));
        $pending[] = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'created_at' => $now, 'return' => $returnTo];
        Session::set(self::key($config), array_slice($pending, -self::MAX_PENDING));

        return [
            'state' => $state,
            'nonce' => $nonce,
            'challenge' => Encoding::encode(hash('sha256', $verifier, binary: true)),
        ];
    }

    /** @return array{nonce: string, verifier: string, return: string} */
    public static function consume(Provider $config, string $state): array
    {
        $stored = self::stored($config);
        if ($stored === []) {
            throw new LoginException($config->reason('NO_FLOW'));
        }

        $now = time();
        $matched = null;
        $remaining = [];
        foreach ($stored as $attempt) {
            if ($matched === null && $state !== '' && hash_equals($attempt['state'], $state)) {
                $matched = $attempt;
                continue;
            }
            if (!self::expired($attempt, $config, $now)) {
                $remaining[] = $attempt;
            }
        }

        // Consume only the matching tab before redemption can fail.
        Session::set(self::key($config), $remaining);
        if ($matched === null) {
            throw new LoginException($config->reason('STATE_MISMATCH'));
        }
        if (self::expired($matched, $config, $now)) {
            throw new LoginException($config->reason('FLOW_EXPIRED'));
        }
        if ($matched['nonce'] === '' || $matched['verifier'] === '') {
            throw new LoginException($config->reason('NO_FLOW'));
        }

        return ['nonce' => $matched['nonce'], 'verifier' => $matched['verifier'], 'return' => $matched['return']];
    }

    public static function discard(Provider $config): void
    {
        Session::forget(self::key($config));
    }

    /** @param array{state: string, nonce: string, challenge: string} $flow */
    public static function authorizeUrl(Provider $config, array $flow): string
    {
        $registration = $config->registration;
        // Hints first: a provider-specific extra can never displace a protocol parameter.
        $query = [
            ...$registration->authorizeHints(),
            'client_id' => $registration->clientId,
            'response_type' => 'code',
            'redirect_uri' => $registration->redirectUri,
            'scope' => $registration->scopeList(),
            'state' => $flow['state'],
            'nonce' => $flow['nonce'],
            'code_challenge' => $flow['challenge'],
            'code_challenge_method' => 'S256',
        ];

        return $registration->authorizeUrl() . '?' . http_build_query($query, arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);
    }

    /** @return list<array{state: string, nonce: string, verifier: string, created_at: int, return: string}> */
    private static function stored(Provider $config): array
    {
        /** @var mixed $stored */
        $stored = Session::get(self::key($config));
        if (!is_array($stored)) {
            return [];
        }

        $attempts = [];
        /** @var mixed $attempt */
        foreach ($stored as $attempt) {
            if (!is_array($attempt)
                || !is_string($attempt['state'] ?? null)
                || !is_string($attempt['nonce'] ?? null)
                || !is_string($attempt['verifier'] ?? null)
                || !is_int($attempt['created_at'] ?? null)
            ) {
                continue;
            }
            // An attempt without a return target still completes, to success_redirect.
            /** @var mixed $return */
            $return = $attempt['return'] ?? '';
            $attempts[] = [
                'state' => $attempt['state'],
                'nonce' => $attempt['nonce'],
                'verifier' => $attempt['verifier'],
                'created_at' => $attempt['created_at'],
                'return' => is_string($return) ? $return : '',
            ];
        }

        return $attempts;
    }

    /** @param array{state: string, nonce: string, verifier: string, created_at: int, return: string} $attempt */
    private static function expired(array $attempt, Provider $config, int $now): bool
    {
        $age = $now - $attempt['created_at'];
        return $attempt['created_at'] <= 0 || $age < 0 || $age > $config->flowTtl;
    }

    private static function key(Provider $config): string
    {
        return $config->sessionKey . '.flow';
    }
}
