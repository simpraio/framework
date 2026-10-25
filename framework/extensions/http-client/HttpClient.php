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
        // First, so a refused URL - credentials included - is never carried in an exception.
        if (Url::parse($url) === null) {
            throw new HttpClientException('HTTP client URL rejected', self::safeUrlLabel($url));
        }

        try {
            $requestOptions = new RequestOptions($options);
        } catch (InvalidArgumentException $e) {
            throw new HttpClientException($e->getMessage(), self::safeUrlLabel($url));
        }

        $config = CoreConfig::extensionConfig(Config::NAME, Config::class);

        if (!$config->enabled) {
            throw new HttpClientException('HTTP client is disabled', $url);
        }

        if (!$config->egress->allows($url)) {
            throw new HttpClientException('HTTP client egress blocked', self::safeUrlLabel($url));
        }

        return self::attempt($config, $url, $requestOptions);
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
        // Revalidate every dial, including retries and redirect hops.
        $pinned = $config->egress->resolvedFor($url);
        if ($pinned === null) {
            throw new HttpClientException('HTTP client egress blocked', self::safeUrlLabel($url));
        }

        $handle = curl_init();
        $responseHeaders = [];
        $body = '';

        $curlOptions = Options::curl($config, $url, $options, $responseHeaders, $body);
        if ($pinned !== []) {
            // Prevent DNS rebinding by pinning the validated addresses.
            $curlOptions[CURLOPT_RESOLVE] = [Resolve::entry($url, $pinned)];
        }

        self::configure($handle, $curlOptions, $url);

        [$ok, $error, $status, $message] = self::execute($handle);

        unset($handle);

        if ($ok !== false && $error === 0) {
            if ($options->followRedirects && Redirect::isStatus($status) && $redirects < Redirect::MAX) {
                $target = Redirect::target($url, $responseHeaders['location'] ?? null);
                if ($target !== null) {
                    // Checked even with egress off: a redirect can introduce what the caller never sent.
                    if (Url::parse($target) === null) {
                        throw new HttpClientException('HTTP client redirect URL rejected', self::safeUrlLabel($target));
                    }
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

        $shouldRetry = Retry::permitted($error, $options) && $retry < $config->retries;

        if (!$shouldRetry) {
            throw new HttpClientException($message, $url, $error);
        }

        if ($config->retryDelay > 0) {
            sleep($config->retryDelay);
        }

        return self::attempt($config, $url, $options, $retry + 1, $redirects);
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

    private static function safeUrlLabel(string $url): string
    {
        return Url::parse($url)?->label() ?? '[unparseable-url]';
    }

    /** Unparseable on either side counts as a different origin, so headers stay behind. */
    private static function sameOrigin(string $from, string $to): bool
    {
        $a = Url::parse($from);
        $b = Url::parse($to);

        return $a !== null && $b !== null && $a->origin() === $b->origin();
    }
}
