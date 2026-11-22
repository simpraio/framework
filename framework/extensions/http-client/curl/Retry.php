<?php

declare(strict_types=1);

namespace extensions\http_client\curl;

/**
 * Retry selected transport failures only when duplicate arrival is safe; never retry an HTTP verdict.
 */
final class Retry
{
    /** Transient failures worth another attempt. Some can follow a request that was sent, so only a safe one is repeated. */
    private const array TRANSIENT = [
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
        // A server may treat even GET with a body as a write; its verb alone cannot make retries safe.
        if ($options->hasBody()) {
            return false;
        }

        return in_array($options->method, self::SAFE_METHODS, strict: true)
            && in_array($error, self::TRANSIENT, strict: true);
    }
}
