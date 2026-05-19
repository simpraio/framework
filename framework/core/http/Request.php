<?php

declare(strict_types=1);

namespace core\http;

final class Request
{
    /** @var array<string, mixed> */
    private array $jsonCache;
    private bool $jsonParsed = false;
    private const array ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
    private const array SERVER_HEADER_MAP = [
        'content-type' => 'CONTENT_TYPE',
        'content-length' => 'CONTENT_LENGTH',
    ];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function __construct(
        private readonly array $query,
        private readonly array $post,
        private readonly array $cookies,
        private readonly array $files,
        private readonly array $server,
        private readonly string $rawBody,
    ) {
    }

    public static function capture(): self
    {
        /** @var array<string, mixed> $get */
        $get = $_GET;
        /** @var array<string, mixed> $post */
        $post = $_POST;
        /** @var array<string, mixed> $cookies */
        $cookies = $_COOKIE;
        /** @var array<string, mixed> $files */
        $files = $_FILES;
        /** @var array<string, mixed> $server */
        $server = $_SERVER;

        return new self(
            query: $get,
            post: $post,
            cookies: $cookies,
            files: $files,
            server: $server,
            rawBody: (string)file_get_contents('php://input'),
        );
    }

    /** @param array<string, mixed> $arr */
    private function getString(array $arr, string $key, ?string $default = null): ?string
    {
        /** @var mixed $value */
        $value = $arr[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    public function method(): string
    {
        $m = strtoupper($this->getString($this->server, 'REQUEST_METHOD') ?? 'GET');
        return in_array(needle: $m, haystack: self::ALLOWED_METHODS, strict: true) ? $m : 'GET';
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    public function path(): string
    {
        $uri = $this->getString($this->server, 'REQUEST_URI') ?? '/';
        $q = strpos(haystack: $uri, needle: '?');
        return $q === false ? $uri : substr(string: $uri, offset: 0, length: $q);
    }

    public function uri(): string
    {
        return $this->getString($this->server, 'REQUEST_URI') ?? '/';
    }

    public function query(string $key, ?string $default = null): ?string
    {
        return $this->getString($this->query, $key, $default);
    }

    public function post(string $key, ?string $default = null): ?string
    {
        return $this->getString($this->post, $key, $default);
    }

    public function input(string $key, ?string $default = null): ?string
    {
        return $this->getString($this->post, $key) ?? $this->getString($this->query, $key, $default);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->post + $this->query;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace(search: '-', replace: '_', subject: $name));
        $primary = $this->getString($this->server, $key) ?? '';
        $v = $primary !== '' ? $primary : ($this->getString(
            $this->server,
            self::SERVER_HEADER_MAP[strtolower($name)] ?? ''
        ) ?? '');
        return $v !== '' ? $v : $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->getString($this->cookies, $name, $default);
    }

    /** @return array<string, mixed>|null */
    public function file(string $key): ?array
    {
        /** @var mixed $value */
        $value = $this->files[$key] ?? null;
        if (!is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    public function ip(): string
    {
        return $this->getString($this->server, 'REMOTE_ADDR') ?? '';
    }

    public function isJson(): bool
    {
        return RequestPayload::isJson($this->header('Content-Type'));
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->jsonParsed) {
            return $this->jsonCache;
        }

        $this->jsonParsed = true;
        $this->jsonCache = RequestPayload::json($this->rawBody, $this->header('Content-Type'));
        return $this->jsonCache;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function isSecure(): bool
    {
        $https = $this->getString($this->server, 'HTTPS') ?? '';
        return ($https !== '' && strcasecmp($https, 'off') !== 0)
            || (string)($this->server['SERVER_PORT'] ?? '') === '443';
    }

    public function isAjax(): bool
    {
        return strcasecmp($this->header('X-Requested-With', '') ?? '', 'XMLHttpRequest') === 0;
    }
}
