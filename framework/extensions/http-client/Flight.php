<?php

declare(strict_types=1);

namespace extensions\http_client;

use Throwable;

/** One HttpClient::request() across retries and redirect hops: its deadline, and whether any of it was sent. */
final class Flight
{
    /** cURL stops with these before it writes any of the request: name resolution, connecting, or setting up TLS. */
    private const array BEFORE_SENDING = [
        CURLE_COULDNT_RESOLVE_PROXY,
        CURLE_COULDNT_RESOLVE_HOST,
        CURLE_COULDNT_CONNECT,
        CURLE_SSL_CONNECT_ERROR,
        // PHP has no CURLE_PEER_FAILED_VERIFICATION: from libcurl 7.62 both of these name its 60,
        // before that the peer check was 51 and the CA check 60.
        CURLE_SSL_PEER_CERTIFICATE,
        CURLE_SSL_CACERT,
        CURLE_SSL_CERTPROBLEM,
        CURLE_SSL_CACERT_BADFILE,
    ];

    private bool $sent = false;

    public function __construct(public readonly Deadline $deadline)
    {
    }

    /** What is left of the call's time, in milliseconds; none ends the call with cURL's timeout error. */
    public function timeLeft(string $url): int
    {
        $remaining = $this->deadline->remainingMs();
        if ($remaining < 1) {
            throw $this->failure('HTTP client timeout exceeded', $url, CURLE_OPERATION_TIMEDOUT);
        }

        return $remaining;
    }

    /** Called with the cURL error once curl_exec has returned; an attempt that sent nothing does not undo an earlier one. */
    public function transferred(int $error): void
    {
        $this->sent = $this->sent || !in_array($error, self::BEFORE_SENDING, strict: true);
    }

    public function failure(string $message, string $url, int $curlError = 0, ?Throwable $previous = null): HttpClientException
    {
        return new HttpClientException($message, $url, $curlError, $this->sent, $previous);
    }
}
