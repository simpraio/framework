<?php

declare(strict_types=1);

namespace core\http;

use InvalidArgumentException;

final class Response
{
    /**
     * The headers a shared cache takes its policy from, lower-cased. A CDN reads a targeted one -
     * CDN-Cache-Control, Cloudflare-CDN-Cache-Control, Surrogate-Control, Akamai's Edge-Control - and
     * nginx reads X-Accel-Expires, each ahead of Cache-Control.
     */
    public const array SHARED_CACHE_HEADERS = [
        'cache-control',
        'cdn-cache-control',
        'cloudflare-cdn-cache-control',
        'surrogate-control',
        'edge-control',
        'x-accel-expires',
        'expires',
    ];

    /** @var array<string, string> */
    private array $headers = [];

    /** @var list<array{name: string, value: string, options: array<string, mixed>}> */
    public private(set) array $cookies = [];

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

    /** @throws \JsonException for data JSON cannot carry, such as invalid UTF-8 or a non-finite float */
    public static function json(mixed $data, int $status = 200): self
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new self($encoded, $status, 'application/json; charset=UTF-8');
    }

    public static function redirect(string $location, int $status = 302): self
    {
        if (!Url::isLocal($location)) {
            throw new InvalidArgumentException('Unsafe redirect target: ' . $location);
        }
        $r = new self('', $status);
        $r->headers['Location'] = self::sanitizeHeaderValue($location);
        return $r;
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    /**
     * An error no shared cache may keep, whatever Cache-Control a site sets for its pages: it depends
     * on the request or on who sent it (400, 401, 403, 429), or it is transient (5xx). A CDN storing
     * one under the URL would serve one client's refusal to everyone. A 404 or 410 is the same for
     * every visitor.
     */
    public static function isUncacheableError(int $status): bool
    {
        return $status >= 400 && $status !== 404 && $status !== 410;
    }

    /**
     * Whether this request has bound its response to whoever sent it, whatever the status: it used a
     * session - $_SESSION stays set after the session is closed or destroyed - carried credentials, or
     * set a cookie outside a Response. A shared cache keeping that response would serve it to others.
     */
    public static function isVisitorBound(): bool
    {
        return ($_SESSION ?? null) !== null
            // Non-empty only: a server may pass any of these on empty for a request that carried none -
            // nginx's fastcgi_param without if_not_empty, an unguarded Apache [E=HTTP_AUTHORIZATION:...]
            // rule, renamed REDIRECT_HTTP_AUTHORIZATION by a front-controller rewrite. A Basic login with
            // an empty user name still shows in PHP_AUTH_PW.
            || array_any(
                ['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'PHP_AUTH_PW', 'PHP_AUTH_DIGEST',
                    'REMOTE_USER', 'REDIRECT_REMOTE_USER'],
                static fn(string $key): bool => ($_SERVER[$key] ?? '') !== '',
            )
            || array_any(headers_list(), static fn(string $line): bool => stripos($line, needle: 'set-cookie:') === 0);
    }

    public function removeHeader(string $name): self
    {
        unset($this->headers[$this->normalizeHeader($name)]);
        return $this;
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
        if (!HeaderField::isName($name)) {
            throw new InvalidArgumentException('Header name is invalid');
        }

        return $name;
    }

    private static function sanitizeHeaderValue(string $value): string
    {
        if (!HeaderField::isValue($value)) {
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
            // Replacing a Set-Cookie would drop every cookie sent before it, the session's among them.
            header("{$name}: {$value}", replace: $name !== 'Set-Cookie');
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
