<?php

declare(strict_types=1);

namespace core;

use core\http\Request as HttpRequest;

final class Request
{
    private static HttpRequest $request;

    public static function init(HttpRequest $request): void
    {
        self::$request = $request;
    }

    public static function instance(): HttpRequest
    {
        return self::$request;
    }

    public static function method(): string
    {
        return self::$request->method();
    }

    public static function isMethod(string $method): bool
    {
        return self::$request->isMethod($method);
    }

    public static function path(): string
    {
        return self::$request->path();
    }

    public static function uri(): string
    {
        return self::$request->uri();
    }

    public static function query(string $key, ?string $default = null): ?string
    {
        return self::$request->query($key, $default);
    }

    public static function input(string $key, ?string $default = null): ?string
    {
        return self::$request->input($key, $default);
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return self::$request->all();
    }

    public static function header(string $name, ?string $default = null): ?string
    {
        return self::$request->header($name, $default);
    }

    public static function cookie(string $name, ?string $default = null): ?string
    {
        return self::$request->cookie($name, $default);
    }

    /** @return array<string, mixed>|null */
    public static function file(string $key): ?array
    {
        return self::$request->file($key);
    }

    public static function ip(): string
    {
        return self::$request->ip();
    }

    public static function isJson(): bool
    {
        return self::$request->isJson();
    }

    /** @return array<string, mixed> */
    public static function json(): array
    {
        return self::$request->json();
    }

    public static function rawBody(): string
    {
        return self::$request->rawBody();
    }

    public static function isSecure(): bool
    {
        return self::$request->isSecure();
    }

    public static function isAjax(): bool
    {
        return self::$request->isAjax();
    }
}
