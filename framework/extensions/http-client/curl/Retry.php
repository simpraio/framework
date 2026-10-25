<?php

declare(strict_types=1);

namespace extensions\http_client\curl;

/**
 * Whether a failed attempt may be sent again.
 *
 * Only an ambiguous transport failure qualifies - one where the request may or may not
 * have reached the server - and only for a request that is safe to arrive twice.
 */
final class Retry
{
    /** Failures that leave it unknown whether the server acted on the request. */
    private const array AMBIGUOUS = [
        CURLE_OPERATION_TIMEDOUT,
        CURLE_COULDNT_CONNECT,
        CURLE_COULDNT_RESOLVE_HOST,
        CURLE_GOT_NOTHING,
        CURLE_SEND_ERROR,
        CURLE_RECV_ERROR,
    ];

    /** Verbs a server is required to treat as safe to repeat. */
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public static function permitted(int $error, RequestOptions $options): bool
    {
        // A body disqualifies a request whatever its verb says. The verb is what this
        // client meant; the body is what the server was handed, and a server is free to
        // treat a GET carrying one as a write. An ambiguous failure cannot say whether
        // it already did.
        if ($options->hasBody()) {
            return false;
        }

        return in_array($options->method, self::SAFE_METHODS, strict: true)
            && in_array($error, self::AMBIGUOUS, strict: true);
    }
}
