<?php

declare(strict_types=1);

namespace extensions\http_client;

use core\config\Config as CoreConfig;
use extensions\http_client\curl\Options;
use extensions\http_client\curl\RequestOptions;

final class HttpClient
{
    /** @param array<string, mixed> $options */
    public static function request(string $url, array $options = []): Response
    {
        $config = CoreConfig::extensionConfig(Config::NAME, Config::class);

        if (!$config->enabled) {
            throw new HttpClientException('HTTP client is disabled', $url);
        }

        if (!$config->egress->allows($url)) {
            throw new HttpClientException('HTTP client egress blocked', self::safeUrlLabel($url));
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

    private static function attempt(
        Config $config,
        string $url,
        RequestOptions $options,
        int $retry = 0,
        int $redirects = 0,
    ): Response
    {
        $handle = curl_init();
        $responseHeaders = [];
        $body = '';

        curl_setopt_array($handle, Options::curl($config, $url, $options, $responseHeaders, $body));

        $ok = curl_exec($handle);
        $error = curl_errno($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $message = curl_error($handle);

        // Drop the handle before retry recursion so retries do not stack live handles.
        unset($handle);

        if ($ok !== false && $error === 0) {
            if (Redirect::isStatus($status) && $redirects < Redirect::MAX) {
                $target = Redirect::target($url, $responseHeaders['location'] ?? null);
                if ($target !== null) {
                    if (!$config->egress->allows($target)) {
                        throw new HttpClientException('HTTP client redirect egress blocked', self::safeUrlLabel($target));
                    }

                    return self::attempt($config, $target, $options->forRedirect($status, self::sameOrigin($url, $target)), 0, $redirects + 1);
                }
            }

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

        $shouldRetry = self::isRetryable($error, $options->method) && $retry < $config->retries;

        if (!$shouldRetry) {
            throw new HttpClientException($message, $url, $error);
        }

        if ($config->retryDelay > 0) {
            sleep($config->retryDelay);
        }

        return self::attempt($config, $url, $options, $retry + 1, $redirects);
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

    private static function safeUrlLabel(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (!is_string($host)) {
            return '[unparseable-url]';
        }

        $label = (is_string($scheme) ? strtolower($scheme) : 'unknown') . '://' . strtolower($host);
        return is_int($port) ? $label . ':' . $port : $label;
    }

    private static function sameOrigin(string $from, string $to): bool
    {
        return self::origin($from) === self::origin($to);
    }

    /** @return array{0: string, 1: string, 2: int}|null */
    private static function origin(string $url): ?array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (!is_string($scheme) || !is_string($host)) {
            return null;
        }

        $scheme = strtolower($scheme);
        if (!is_int($port)) {
            $port = $scheme === 'https' ? 443 : 80;
        }

        return [$scheme, strtolower($host), $port];
    }
}
