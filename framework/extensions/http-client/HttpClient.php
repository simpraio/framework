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
            throw new HttpClientException('HTTP client URL rejected', $url, sent: false);
        }

        try {
            $requestOptions = new RequestOptions($options);
        } catch (InvalidArgumentException $e) {
            throw new HttpClientException($e->getMessage(), $url, sent: false);
        }

        $config = CoreConfig::extensionConfig(Config::NAME, Config::class);

        if (!$config->enabled) {
            throw new HttpClientException('HTTP client is disabled', $url, sent: false);
        }

        // Before the egress check: it resolves the host, and that time is part of the call.
        $flight = new Flight(Deadline::in(Options::callTimeout($config, $requestOptions)));

        return self::attempt($config, $url, $requestOptions, $flight);
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
        Flight $flight,
        int $redirects = 0,
    ): Response
    {
        $retry = 0;
        while (true) {
            [$ok, $error, $status, $message, $responseHeaders, $body] = self::dial($config, $url, $options, $flight, $redirects > 0);

            if ($ok && $error === 0) {
                $target = $options->followRedirects && $redirects < Redirect::MAX
                    ? self::redirectTarget($url, $status, $responseHeaders, $flight)
                    : null;
                if ($target === null) {
                    return new Response(status: $status, headers: $responseHeaders, body: $body);
                }

                return self::attempt($config, $target, $options->forRedirect($status, Redirect::sameOrigin($url, $target)), $flight, $redirects + 1);
            }

            if ($error === CURLE_WRITE_ERROR) {
                throw $flight->failure("Response exceeded {$config->maxResponseBytes} bytes", $url, $error);
            }

            $shouldRetry = Retry::permitted($error, $options)
                && $retry < $config->retries
                && $flight->deadline->remainingMs() > $config->retryDelay * 1000;

            if (!$shouldRetry) {
                throw $flight->failure($message, $url, $error);
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
    private static function dial(Config $config, string $url, RequestOptions $options, Flight $flight, bool $redirect): array
    {
        // A hop or retry with no time left is not worth a lookup.
        $flight->timeLeft($url);
        // Revalidate every hop and retry, then pin this resolution: no second lookup, and DNS rebinding cannot swap the address.
        $pinned = $config->egress->resolvedFor($url);
        if ($pinned === null) {
            throw $flight->failure($redirect ? 'HTTP client redirect egress blocked' : 'HTTP client egress blocked', $url);
        }
        // A lookup cannot be cut short: what it took comes out of what is left for the transfer.
        $remaining = $flight->timeLeft($url);

        $handle = curl_init();
        $responseHeaders = [];
        $body = '';

        try {
            $curlOptions = Options::curl($config, $url, $options, $responseHeaders, $body);
        } catch (HttpClientException $rejected) {
            throw $flight->failure($rejected->getMessage(), $url, $rejected->curlError, $rejected);
        }
        if ($pinned !== []) {
            $curlOptions[CURLOPT_RESOLVE] = [Resolve::entry($url, $pinned)];
        }

        // The attempt gets what is left of the call's timeout, not a timeout of its own.
        unset($curlOptions[CURLOPT_TIMEOUT]);
        $curlOptions[CURLOPT_TIMEOUT_MS] = $remaining;

        self::configure($handle, $curlOptions, $url, $flight);

        [$ok, $error, $status, $message] = self::execute($handle, $flight);

        unset($handle);

        return [$ok, $error, $status, $message, $responseHeaders, $body];
    }

    /**
     * curl_setopt_array() stops at the first rejected option; never send with partial configuration.
     *
     * @param array<int, mixed> $curlOptions
     */
    private static function configure(\CurlHandle $handle, array $curlOptions, string $url, Flight $flight): void
    {
        if (!curl_setopt_array($handle, $curlOptions)) {
            throw $flight->failure('Could not configure the cURL handle', $url);
        }
    }

    /** @return array{0: bool, 1: int, 2: int, 3: string} [ok, curlError, httpStatus, curlMessage] */
    private static function execute(\CurlHandle $handle, Flight $flight): array
    {
        $ok = curl_exec($handle) !== false;
        $error = curl_errno($handle);
        $flight->transferred($error);

        return [
            $ok,
            $error,
            (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            curl_error($handle),
        ];
    }

    /**
     * Where a redirect response sends the request next, or null when it is not followed.
     *
     * @param array<string, string|list<string>> $responseHeaders
     */
    private static function redirectTarget(string $url, int $status, array $responseHeaders, Flight $flight): ?string
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
            throw $flight->failure('HTTP client redirect URL rejected', $target);
        }
        // A cross-origin hop carries no credentials, but its path, query and response would
        // still travel in the clear, unauthenticated.
        if (Redirect::isDowngrade($url, $target)) {
            throw $flight->failure('HTTP client redirect downgrade rejected', $target);
        }

        return $target;
    }

}
