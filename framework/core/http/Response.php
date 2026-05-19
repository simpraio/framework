<?php

declare(strict_types=1);

namespace core\http;

use InvalidArgumentException;

final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var list<array{name: string, value: string, options: array<string, mixed>}> */
    private array $cookies = [];

    public function __construct(
        private(set) string $body = '',
        private(set) int $status = 200,
        string $contentType = 'text/html; charset=UTF-8',
    ) {
        $this->headers['Content-Type'] = $contentType;
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, 'text/plain; charset=UTF-8');
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, 'text/html; charset=UTF-8');
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new self($encoded === false ? 'null' : $encoded, $status, 'application/json; charset=UTF-8');
    }

    public static function redirect(string $location, int $status = 302): self
    {
        if (!self::isAllowedRedirect($location)) {
            throw new InvalidArgumentException('Unsafe redirect target: ' . $location);
        }
        $r = new self('', $status);
        $r->headers['Location'] = self::sanitizeHeaderValue($location);
        return $r;
    }

    private static function isAllowedRedirect(string $location): bool
    {
        if ($location === '' || preg_match('/[\r\n\0]/', $location) === 1) {
            return false;
        }
        if (str_starts_with($location, '//') || str_starts_with($location, '/\\')) {
            return false;
        }
        return $location[0] === '/';
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public function status(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$this->normalizeHeader(self::sanitizeHeaderName($name))] = self::sanitizeHeaderValue($value);
        return $this;
    }

    private static function sanitizeHeaderName(string $name): string
    {
        if ($name === '' || preg_match('/[\r\n\0:]/', $name) === 1) {
            throw new InvalidArgumentException('Header name is invalid');
        }

        return $name;
    }

    private static function sanitizeHeaderValue(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException('Header value contains CR, LF, or NUL');
        }
        return $value;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$this->normalizeHeader($name)] ?? null;
    }

    public function body(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    /** @param array<string, mixed> $options */
    public function cookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];
        return $this;
    }

    public function clearCookie(string $name, string $path = '/', string $domain = ''): self
    {
        $this->cookies[] = [
            'name' => $name,
            'value' => '',
            'options' => ['expires' => time() - 42_000, 'path' => $path, 'domain' => $domain],
        ];
        return $this;
    }

    public function send(): void
    {
        if (headers_sent()) {
            echo $this->body;
            return;
        }

        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}", replace: true);
        }

        foreach ($this->cookies as $cookie) {
            setcookie($cookie['name'], $cookie['value'], $cookie['options']);
        }

        echo in_array($this->status, [204, 205, 304], strict: true) ? '' : $this->body;
    }

    private function normalizeHeader(string $name): string
    {
        $parts = explode('-', strtolower($name));
        foreach ($parts as $i => $p) {
            $parts[$i] = ucfirst($p);
        }
        return implode('-', $parts);
    }
}
