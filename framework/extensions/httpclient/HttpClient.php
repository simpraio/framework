<?php

declare(strict_types=1);

namespace extensions\httpclient;

use core\config\Config as CoreConfig;
use extensions\httpclient\curl\Options;
use extensions\httpclient\curl\RequestOptions;

final class HttpClient
{
    /** @param array<string, mixed> $options */
    public static function request(string $url, array $options = []): Response
    {
        $config = CoreConfig::extensionConfig(Config::NAME, Config::class);

        if (!$config->enabled) {
            throw new HttpClientException('HTTP client is disabled', $url);
        }

        return self::attempt($config, $url, Options::normalize($options));
    }

    /** @param array<string, mixed> $options */
    public static function get(string $url, array $options = []): Response
    {
        return self::request($url, ['method' => 'GET', ...$options]);
    }

    /** @param array<string, mixed> $options */
    public static function post(string $url, array $options = []): Response
    {
        return self::request($url, ['method' => 'POST', ...$options]);
    }

    private static function attempt(Config $config, string $url, RequestOptions $options, int $retry = 0): Response
    {
        $handle = curl_init();
        $responseHeaders = [];
        $body = '';

        curl_setopt_array($handle, Options::curl($config, $url, $options, $responseHeaders, $body));

        $ok = curl_exec($handle);
        $error = curl_errno($handle);

        if ($ok !== false && $error === 0) {
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            return new Response(
                status: $status,
                headers: $responseHeaders,
                body: $body,
                json: Payload::json($responseHeaders, $body),
            );
        }

        if ($error === CURLE_WRITE_ERROR) {
            throw new HttpClientException(
                "Response exceeded {$config->maxResponseBytes} bytes",
                $url,
                $error,
            );
        }

        $message = curl_error($handle);
        $shouldRetry = self::isRetryable($error, $options->method) && $retry < $config->retries;

        if (!$shouldRetry) {
            throw new HttpClientException($message, $url, $error);
        }

        if ($config->retryDelay > 0) {
            sleep($config->retryDelay);
        }

        return self::attempt($config, $url, $options, $retry + 1);
    }

    /**
     * Retry only "safe" methods (RFC 7231 section 4.2.1) - those without intended
     * side effects. Retrying POST/PATCH/PUT/DELETE on transient send/recv
     * errors risks duplicate execution server-side (e.g. double charge).
     */
    private static function isRetryable(int $error, string $method): bool
    {
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], strict: true)) {
            return false;
        }

        return in_array($error, [
            CURLE_OPERATION_TIMEDOUT,
            CURLE_COULDNT_CONNECT,
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_GOT_NOTHING,
            CURLE_SEND_ERROR,
            CURLE_RECV_ERROR,
        ], strict: true);
    }
}
