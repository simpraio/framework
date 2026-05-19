<?php

declare(strict_types=1);

namespace extensions\httpclient\curl;

use extensions\httpclient\Config;
use extensions\httpclient\HttpClientException;

final class Options
{
    /** @param array<string, mixed> $options */
    public static function normalize(array $options): RequestOptions
    {
        return new RequestOptions($options);
    }

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
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS_STR => Policy::allowedProtocols($config),
            CURLOPT_REDIR_PROTOCOLS_STR => Policy::allowedProtocols($config),
            CURLOPT_CONNECTTIMEOUT => Policy::connectTimeout($config, $options),
            CURLOPT_TIMEOUT => Policy::timeout($config, $options),
            CURLOPT_HEADER => false,
            CURLOPT_VERBOSE => false,
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => $options->method === 'GET',
            CURLOPT_POST => $options->method === 'POST',
            CURLOPT_PROXY => $options->proxy,
            CURLOPT_HTTPHEADER => Headers::request($options->headers),
            CURLOPT_HEADERFUNCTION => Headers::response($responseHeaders),
        ];

        return $base
            + self::cookieOptions($config, $options, $url)
            + Body::options($options)
            + Method::options($options->method);
    }

    private static function userAgent(): string
    {
        $userAgent = ini_get('user_agent');

        return is_string($userAgent) && $userAgent !== '' ? $userAgent : 'SIMPRA HTTP request/1.0';
    }

    /** @return array<int, mixed> */
    private static function cookieOptions(Config $config, RequestOptions $options, string $url): array
    {
        if ($options->cookies === null) {
            return [];
        }

        if ($config->cookieJarDir === null) {
            throw new HttpClientException(
                'Cookie jar usage requires extensions.httpclient.cookie_jar_dir to be configured',
                $url,
            );
        }

        $jarDir = realpath($config->cookieJarDir);
        if ($jarDir === false) {
            throw new HttpClientException(
                'Configured cookie_jar_dir does not exist: ' . $config->cookieJarDir,
                $url,
            );
        }

        $cookieDir = realpath(dirname($options->cookies));
        if ($cookieDir === false) {
            throw new HttpClientException(
                'Cookie file directory does not exist: ' . dirname($options->cookies),
                $url,
            );
        }

        if ($cookieDir !== $jarDir
            && !str_starts_with($cookieDir, $jarDir . DIRECTORY_SEPARATOR)
        ) {
            throw new HttpClientException(
                'Cookie path must be under cookie_jar_dir: ' . $config->cookieJarDir,
                $url,
            );
        }

        return [
            CURLOPT_COOKIEJAR => $options->cookies,
            CURLOPT_COOKIEFILE => $options->cookies,
        ];
    }
}
