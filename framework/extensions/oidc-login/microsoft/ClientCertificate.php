<?php

declare(strict_types=1);

namespace extensions\oidc_login\microsoft;

use core\tools\Silently;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

final readonly class ClientCertificate
{
    private function __construct(
        public OpenSSLAsymmetricKey $key,
        /** SHA-256 over the certificate's DER encoding: the x5t#S256 header value, before encoding. */
        public string $thumbprint,
    ) {
    }

    /**
     * Null unless readable PEM files supply a certificate and its matching unencrypted RSA key of at least 2048 bits.
     */
    public static function load(string $certificateFile, string $privateKeyFile): ?self
    {
        $certificate = self::certificate(self::read($certificateFile));
        $key = self::privateKey(self::read($privateKeyFile));
        if ($certificate === null || $key === null || !openssl_x509_check_private_key($certificate, $key)) {
            return null;
        }

        // Fingerprint the same parsed certificate checked against the key, not a separately extracted PEM block.
        $thumbprint = openssl_x509_fingerprint($certificate, digest_algo: 'sha256', binary: true);

        return is_string($thumbprint) ? new self($key, $thumbprint) : null;
    }

    /**
     * Suppress filesystem warnings so credential failures cannot expose paths through the error handler.
     */
    private static function read(string $file): ?string
    {
        $contents = Silently::call(static fn(): string|false => is_file($file) && is_readable($file) ? file_get_contents($file) : false);

        return is_string($contents) ? $contents : null;
    }

    private static function certificate(?string $pem): ?OpenSSLCertificate
    {
        // @mago-expect analysis:invalid-argument -- openssl_x509_read() takes OpenSSLCertificate|string; the analyzer's stub omits string.
        $certificate = $pem === null ? false : Silently::call(static fn(): OpenSSLCertificate|false => openssl_x509_read($pem));

        return $certificate instanceof OpenSSLCertificate ? $certificate : null;
    }

    /** An unencrypted RSA private key, or null: a passphrase-protected key cannot be opened here. */
    private static function privateKey(?string $pem): ?OpenSSLAsymmetricKey
    {
        $key = $pem === null ? false : openssl_pkey_get_private($pem);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            return null;
        }
        $details = openssl_pkey_get_details($key);

        // PS256 requires an RSA modulus of at least 2048 bits (RFC 7518 section 3.5).
        return is_array($details)
            && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA
            && ($details['bits'] ?? 0) >= 2048 ? $key : null;
    }
}
