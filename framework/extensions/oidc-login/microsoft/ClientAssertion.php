<?php

declare(strict_types=1);

namespace extensions\oidc_login\microsoft;

use core\tools\Silently;
use extensions\oidc_login\oidc\Encoding;
use UnexpectedValueException;

/**
 * PHP 8.5 cannot choose OpenSSL's PSS salt length; require the 32 bytes PS256 specifies after signing.
 */
final class ClientAssertion
{
    public const string TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    /** Entra wants exp at most 5-10 minutes after nbf: 300 s ahead plus the skew below is 6. */
    private const int LIFETIME = 300;

    /** nbf is backdated so a server clock running ahead of Entra's cannot make it "not yet valid". */
    private const int CLOCK_SKEW = 60;

    private const int SHA256_LENGTH = 32;

    /**
     * What this runtime lacks to sign PS256, such as "PHP 8.5", or null. PHP cannot choose the PSS salt
     * length, and before OpenSSL 3.1 its default is the longest the key allows, which fails the 32-byte salt
     * check on every signature. A PHP without openssl is not refused here: that fails at sign-in.
     * The OpenSSL version is read with constant(), not as a constant, so a static analyzer does not fold the
     * check against the build it runs on - keep it that way; the OpenSSL floor suite also relies on it.
     */
    public static function missingRequirement(): ?string
    {
        return match (true) {
            PHP_VERSION_ID < 80_500 => 'PHP 8.5',
            extension_loaded('openssl') && (int)constant('OPENSSL_VERSION_NUMBER') < 0x3010_0000 => 'OpenSSL 3.1',
            default => null,
        };
    }

    /**
     * Null for unusable credentials or invalid UTF-8; never expose file contents or paths on failure.
     * A runtime that cannot sign PS256 throws instead, naming it, since the credential is not at fault:
     * no openssl, or a salt other than 32 bytes. OPENSSL_VERSION_NUMBER is the version PHP was compiled
     * against, so an older library at runtime passes missingRequirement() and is caught only here.
     *
     * @throws UnexpectedValueException
     */
    public static function sign(string $clientId, string $audience, string $certificateFile, string $privateKeyFile): ?string
    {
        if (!extension_loaded('openssl')) {
            throw new UnexpectedValueException('the openssl extension is not loaded');
        }
        if (PHP_VERSION_ID < 80_500) {
            return null;
        }
        $credential = ClientCertificate::load($certificateFile, $privateKeyFile);
        $now = time();
        $claims = self::segment([
            'aud' => $audience,
            'iss' => $clientId,
            'sub' => $clientId,
            'jti' => bin2hex(random_bytes(16)),
            'nbf' => $now - self::CLOCK_SKEW,
            'iat' => $now,
            'exp' => $now + self::LIFETIME,
        ]);
        if ($credential === null || $claims === null) {
            return null;
        }
        $header = self::segment(['alg' => 'PS256', 'typ' => 'JWT', 'x5t#S256' => Encoding::encode($credential->thumbprint)]);
        if ($header === null) {
            return null;
        }
        $input = $header . '.' . $claims;

        return Silently::call(static function () use ($input, $credential): ?string {
            $signature = '';
            // @mago-expect analysis:non-existent-constant,analysis:too-many-arguments -- PHP 8.5+ only; the analyzer targets 8.4.
            if (!openssl_sign($input, $signature, $credential->key, OPENSSL_ALGO_SHA256, OPENSSL_PKCS1_PSS_PADDING)) {
                return null;
            }
            if (PssSalt::length($signature, $credential->key) !== self::SHA256_LENGTH) {
                throw new UnexpectedValueException('PS256 signature salt is not 32 bytes (OpenSSL library older than 3.1?)');
            }

            return $input . '.' . Encoding::encode($signature);
        });
    }

    /**
     * Return null for invalid UTF-8 so the caller can report a credential failure.
     *
     * @param array<string, int|string> $value
     */
    private static function segment(array $value): ?string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);

        return is_string($json) ? Encoding::encode($json) : null;
    }
}
