<?php

declare(strict_types=1);

namespace extensions\http_client;

use core\config\Config as CoreConfig;
use core\http\Url;
use extensions\http_client\curl\Options;
use extensions\http_client\curl\RequestOptions;
use extensions\http_client\curl\Resolve;
use extensions\http_client\curl\Retry;
use InvalidArgumentException;

final class HttpClient
{
    /** @param array<string, mixed> $options */
    public static function request(string $url, array $options = []): Response
    {
        // First, so a refused URL - credentials included - is never sent anywhere.
        if (Url::parse($url) === null) {
            throw new HttpClientException('HTTP client URL rejected', $url);
        }

        try {
            $requestOptions = new RequestOptions($options);
        } catch (InvalidArgumentException $e) {
            throw new HttpClientException($e->getMessage(), $url);
        }

        $config = CoreConfig::extensionConfig(Config::NAME, Config::class);

        if (!$config->enabled) {
            throw new HttpClientException('HTTP client is disabled', $url);
        }

        if (!$config->egress->allows($url)) {
            throw new HttpClientException('HTTP client egress blocked', $url);
        }

        return self::attempt($config, $url, $requestOptions, Deadline::in(Options::callTimeout($config, $requestOptions)));
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
        Deadline $deadline,
        int $redirects = 0,
    ): Response
    {
        $retry = 0;
        while (true) {
            [$ok, $error, $status, $message, $responseHeaders, $body] = self::dial($config, $url, $options, $deadline);

            if ($ok && $error === 0) {
                $target = $options->followRedirects && $redirects < Redirect::MAX
                    ? self::redirectTarget($config, $url, $status, $responseHeaders)
                    : null;
                if ($target === null) {
                    return new Response(status: $status, headers: $responseHeaders, body: $body);
                }

                return self::attempt($config, $target, $options->forRedirect($status, Redirect::sameOrigin($url, $target)), $deadline, $redirects + 1);
            }

            if ($error === CURLE_WRITE_ERROR) {
                throw new HttpClientException(
                    "Response exceeded {$config->maxResponseBytes} bytes",
                    $url,
                    $error,
                );
            }

            $shouldRetry = Retry::permitted($error, $options)
                && $retry < $config->retries
                && $deadline->remainingMs() > $config->retryDelay * 1000;

            if (!$shouldRetry) {
                throw new HttpClientException($message, $url, $error);
            }

            if ($config->retryDelay > 0) {
                sleep($config->retryDelay);
            }
            $retry++;
        }
    }

    /**
     * One request on the wire, given what is left of the call's timeout.
     *
     * @return array{0: bool, 1: int, 2: int, 3: string, 4: array<string, string|list<string>>, 5: string}
     *     [ok, curlError, httpStatus, curlMessage, responseHeaders, body]
     */
    private static function dial(Config $config, string $url, RequestOptions $options, Deadline $deadline): array
    {
        $remaining = $deadline->remainingMs();
        if ($remaining < 1) {
            throw new HttpClientException('HTTP client timeout exceeded', $url, CURLE_OPERATION_TIMEDOUT);
        }

        // Revalidate every dial, including retries and redirect hops.
        $pinned = $config->egress->resolvedFor($url);
        if ($pinned === null) {
            throw new HttpClientException('HTTP client egress blocked', $url);
        }

        $handle = curl_init();
        $responseHeaders = [];
        $body = '';

        $curlOptions = Options::curl($config, $url, $options, $responseHeaders, $body);
        if ($pinned !== []) {
            // Prevent DNS rebinding by pinning the validated addresses.
            $curlOptions[CURLOPT_RESOLVE] = [Resolve::entry($url, $pinned)];
        }

        // The attempt gets what is left of the call's timeout, not a timeout of its own.
        unset($curlOptions[CURLOPT_TIMEOUT]);
        $curlOptions[CURLOPT_TIMEOUT_MS] = $remaining;

        self::configure($handle, $curlOptions, $url);

        [$ok, $error, $status, $message] = self::execute($handle);

        unset($handle);

        return [$ok, $error, $status, $message, $responseHeaders, $body];
    }

    /**
     * curl_setopt_array() stops at the first rejected option; never send with partial configuration.
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
     * Where a redirect response sends the request next, or null when it is not followed.
     *
     * @param array<string, string|list<string>> $responseHeaders
     */
    private static function redirectTarget(Config $config, string $url, int $status, array $responseHeaders): ?string
    {
        if (!Redirect::isStatus($status)) {
            return null;
        }

        $target = Redirect::target($url, $responseHeaders['location'] ?? null);
        if ($target === null) {
            return null;
        }

        // Checked even with egress off: a redirect can introduce what the caller never sent.
        if (Url::parse($target) === null) {
            throw new HttpClientException('HTTP client redirect URL rejected', $target);
        }
        // A cross-origin hop carries no credentials, but its path, query and response would
        // still travel in the clear, unauthenticated.
        if (Redirect::isDowngrade($url, $target)) {
            throw new HttpClientException('HTTP client redirect downgrade rejected', $target);
        }
        if (!$config->egress->allows($target)) {
            throw new HttpClientException('HTTP client redirect egress blocked', $target);
        }

        return $target;
    }

}
