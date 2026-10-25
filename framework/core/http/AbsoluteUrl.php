<?php

declare(strict_types=1);

namespace core\http;

/**
 * An absolute http(s) URL that has passed Url::parse(). Obtain one through Url::parse(), the
 * single entry point for URL handling; this class holds what a parsed URL can tell you.
 */
final readonly class AbsoluteUrl
{
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    private function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        private bool $portGiven,
        public string $path,
        public ?string $query,
        public ?string $fragment,
    ) {
    }

    /**
     * Refused outright rather than interpreted: whitespace, control bytes, backslashes and a
     * percent-encoded host, because two URL parsers can disagree about each of them - and the
     * one that checks a URL is not the one that connects to it. Credentials are refused too:
     * a URL is logged, kept in history and forwarded, and user:secret@ would travel with it.
     */
    public static function from(string $url): ?self
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!array_key_exists($scheme, self::DEFAULT_PORTS) || $host === '' || str_contains($host, '%')) {
            return null;
        }

        // parse_url() already fails on a port past 65535, but accepts 0.
        $port = $parts['port'] ?? null;
        if ($port === 0) {
            return null;
        }

        return new self(
            scheme: $scheme,
            host: $host,
            port: $port ?? self::DEFAULT_PORTS[$scheme],
            portGiven: $port !== null,
            path: $parts['path'] ?? '',
            query: $parts['query'] ?? null,
            fragment: $parts['fragment'] ?? null,
        );
    }

    /** Scheme, host and the port actually connected to: equal for http://h and http://h:80. */
    public function origin(): string
    {
        return $this->scheme . '://' . $this->host . ':' . $this->port;
    }

    /** Safe to log: no path, query or fragment, and a port only if the URL named one. */
    public function label(): string
    {
        return $this->scheme . '://' . $this->host . ($this->portGiven ? ':' . $this->port : '');
    }
}
