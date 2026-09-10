<?php

declare(strict_types=1);

namespace extensions\http_client;

use core\config\Config as CoreConfig;
use extensions\http_client\curl\Options;
use extensions\http_client\curl\Resolve;
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
        // Re-authorize here rather than trusting the caller's check, because this is also the
        // retry and redirect recursion point: every dial gets its own validated addresses.
        $pinned = $config->egress->resolvedFor($url);
        if ($pinned === null) {
            throw new HttpClientException('HTTP client egress blocked', self::safeUrlLabel($url));
        }

        $handle = curl_init();
        $responseHeaders = [];
        $body = '';

        $curlOptions = Options::curl($config, $url, $options, $responseHeaders, $body);
        if ($pinned !== []) {
            // Pin the connection to the addresses the egress guard validated. Without this curl
            // resolves the name a second time and may reach an address that was never checked.
            $curlOptions[CURLOPT_RESOLVE] = [Resolve::entry($url, $pinned)];
        }

        self::configure($handle, $curlOptions, $url);

        [$ok, $error, $status, $message] = self::execute($handle);

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
     * curl_setopt_array() stops at the first option libcurl rejects and applies none after it,
     * with no warning - the pinned addresses, headers, the method and the body all follow the
     * URL, so a partial setup must not send.
     *
     * @param array<int, mixed> $curlOptions
     */
    private static function configure(\CurlHandle $handle, array $curlOptions, string $url): void
    {
        if (!curl_setopt_array($handle, $curlOptions)) {
            throw new HttpClientException('Could not configure the cURL handle', $url);
        }
    }

    /** @return array{0: bool, 1: int, 2: int, 3: string} [ok, curlError, httpStatus, curlMessage] */
    private static function execute(\CurlHandle $handle): array
    {
        return [
            curl_exec($handle) !== false,
            curl_errno($handle),
            (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            curl_error($handle),
        ];
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
