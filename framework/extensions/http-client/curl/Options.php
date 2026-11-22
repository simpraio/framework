<?php

declare(strict_types=1);

namespace extensions\http_client\curl;

use extensions\http_client\Config;
use extensions\http_client\HttpClientException;

final class Options
{
    /**
     * @param array<string, string|list<string>> $responseHeaders
     * @return array<int, mixed>
     */
    public static function curl(Config $config, string $url, RequestOptions $options, array &$responseHeaders, string &$body): array
    {
        $maxBytes = $config->maxResponseBytes;

        $base = [
            CURLOPT_USERAGENT => self::userAgent(),
            CURLOPT_SSL_VERIFYPEER => $config->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $config->verifyTls ? 2 : 0,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS_STR => implode(',', $config->allowedProtocols),
            CURLOPT_CONNECTTIMEOUT => self::timeout($options->connectTimeout, $config->connectTimeout),
            CURLOPT_TIMEOUT => self::callTimeout($config, $options),
            CURLOPT_HEADER => false,
            CURLOPT_VERBOSE => false,
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => $options->method === 'GET',
            CURLOPT_POST => $options->method === 'POST',
            // The empty value stops cURL asking for 100 Continue before a large body: a server that ignores it costs a second.
            CURLOPT_HTTPHEADER => [...Headers::request($options->headers), 'Expect:'],
            CURLOPT_HEADERFUNCTION => Headers::response($responseHeaders),
        ];

        return $base
            + self::proxyOptions($config)
            + self::cookieOptions($config, $options, $url)
            + Body::options($options)
            + Method::options($options->method);
    }

    /**
     * Proxy is config-only so request options cannot bypass the egress allowlist.
     *
     * @return array<int, mixed>
     */
    private static function proxyOptions(Config $config): array
    {
        // Disable environment proxies: proxy-side DNS would bypass validated-address pins.
        if ($config->proxy === null) {
            return [CURLOPT_PROXY => ''];
        }

        return [
            CURLOPT_PROXY => $config->proxy,
            // Environment exclusions must not bypass the configured proxy's egress policy.
            CURLOPT_NOPROXY => '',
        ];
    }

    private static function userAgent(): string
    {
        $userAgent = ini_get('user_agent');

        return is_string($userAgent) && $userAgent !== '' ? $userAgent : 'SIMPRA HTTP request/1.0';
    }

    /** Seconds for the whole call - every attempt, retry delay and redirect hop together. */
    public static function callTimeout(Config $config, RequestOptions $options): int
    {
        return self::timeout($options->timeout, $config->timeout);
    }

    private static function timeout(?int $value, int $default): int
    {
        return $value !== null && $value > 0 ? $value : $default;
    }

    /** @return array<int, mixed> */
    private static function cookieOptions(Config $config, RequestOptions $options, string $url): array
    {
        if ($options->cookies === null) {
            return [];
        }

        if ($config->cookieJarDir === null) {
            throw new HttpClientException(
                'Cookie jar usage requires extensions.http-client.cookie_jar_dir to be configured',
                $url,
            );
        }

        // PHP-FPM keeps these caches across requests, so a path another process has replaced would still be judged as it was.
        clearstatcache(true);

        $jarDir = realpath($config->cookieJarDir);
        if ($jarDir === false || !is_dir($jarDir)) {
            throw new HttpClientException('Configured cookie_jar_dir is not an existing directory', $url);
        }

        $cookieDir = realpath(dirname($options->cookies));
        if ($cookieDir === false) {
            throw new HttpClientException('Cookie file directory does not exist', $url);
        }

        if ($cookieDir !== $jarDir
            && !str_starts_with($cookieDir, $jarDir . DIRECTORY_SEPARATOR)
        ) {
            throw new HttpClientException('Cookie path must be under cookie_jar_dir', $url);
        }

        // cURL gets the checked file, never a link or a directory: it reads and writes through the first, and spins on the second.
        // Nothing here stops another process from swapping the file afterwards, so the jar must be writable by the application only.
        $cookieFile = $cookieDir . DIRECTORY_SEPARATOR . basename($options->cookies);
        if (is_link($cookieFile) || (file_exists($cookieFile) && !is_file($cookieFile))) {
            throw new HttpClientException('Cookie path must be a regular file, not a symbolic link or a directory', $url);
        }

        return [
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
        ];
    }
}
