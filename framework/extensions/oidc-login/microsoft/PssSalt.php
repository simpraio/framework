<?php

declare(strict_types=1);

namespace extensions\oidc_login\microsoft;

use OpenSSLAsymmetricKey;

/**
 * Reads the salt length out of an RSASSA-PSS signature (SHA-256 with MGF1-SHA-256), so a PS256
 * client assertion can be checked against RFC 7518's 32-byte salt before it is sent: PHP signs with
 * the linked OpenSSL's default salt length and offers no way to choose it.
 */
final class PssSalt
{
    private const int HASH_LENGTH = 32;

    /**
     * The salt length, recovered with the public key per RFC 8017 9.1.2 steps 4-10, or null when
     * the signature is not well-formed PSS. The signature itself is not re-verified here: the
     * caller produced it a moment ago with the matching private key.
     */
    public static function length(string $signature, OpenSSLAsymmetricKey $key): ?int
    {
        $details = openssl_pkey_get_details($key);
        $publicKey = is_array($details) ? ($details['key'] ?? null) : null;
        $bits = is_array($details) ? ($details['bits'] ?? null) : null;
        $raw = '';
        if (!is_string($publicKey) || !is_int($bits) || !openssl_public_decrypt($signature, $raw, $publicKey, OPENSSL_NO_PADDING)) {
            return null;
        }

        $emBits = $bits - 1;
        $emLength = intdiv($emBits + 7, num2: 8);
        $encoded = substr($raw, -$emLength);
        // The leading 8 * emLength - emBits bits lie above emBits: zero in maskedDB (step 6) and
        // cleared again after unmasking (step 9).
        $emBitsMask = 0xff >> (8 * $emLength - $emBits);
        if (strlen($encoded) !== $emLength || $emLength < 2 * self::HASH_LENGTH + 2 || ord($encoded[$emLength - 1]) !== 0xbc
            || (ord($encoded[0]) & ~$emBitsMask) !== 0
        ) {
            return null;
        }

        $dbLength = $emLength - self::HASH_LENGTH - 1;
        $db = self::unmask(substr($encoded, offset: 0, length: $dbLength), substr($encoded, $dbLength, self::HASH_LENGTH));
        $db[0] = chr(ord($db[0]) & $emBitsMask);

        // DB = PS (zero bytes) || 0x01 || salt
        $separator = strspn($db, characters: "\x00");
        if ($separator >= $dbLength || $db[$separator] !== "\x01") {
            return null;
        }

        return $dbLength - $separator - 1;
    }

    /** maskedDB XOR MGF1-SHA-256(H). */
    private static function unmask(string $masked, string $hash): string
    {
        $mask = '';
        for ($counter = 0; strlen($mask) < strlen($masked); $counter++) {
            $mask .= hash('sha256', $hash . pack('N', $counter), binary: true);
        }

        return $masked ^ substr($mask, offset: 0, length: strlen($masked));
    }
}
