<?php

declare(strict_types=1);

namespace core\http;

use InvalidArgumentException;

/**
 * The one place a URL or a path is taken apart or judged:
 *
 * - path()           the part of a request target, or any string, before its query or fragment
 * - isLocal()        a finished path on this site, safe to send as a Location
 * - configuredPath() a route from configuration, turned into a path on this site
 * - parse()          an absolute http(s) URL this framework is about to connect to or trust
 * - host()           the host a Host header names
 */
final class Url
{
    /** Browsers may strip or reinterpret these bytes before resolving a Location. */
    private const string CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/';

    /** Everything before the first ? or #. Never fails, whatever the input. */
    public static function path(string $value): string
    {
        return preg_split('/[?#]/', $value, limit: 2)[0] ?? '';
    }

    /**
     * Whether a value is a finished path on this site. Unlike configuredPath(), a bare route
     * name is refused rather than completed.
     */
    public static function isLocal(string $location): bool
    {
        if ($location === '' || self::hasControlCharacters($location)) {
            return false;
        }
        if (str_starts_with($location, '//') || str_starts_with($location, '/\\')) {
            return false;
        }
        return $location[0] === '/';
    }

    /**
     * A leading-slash path stays exact; a bare route name becomes /name/. Browser-equivalent
     * slashes are normalised and control characters refused, so the result cannot leave the site.
     *
     * @throws InvalidArgumentException with $errorCode when the value holds a control character
     */
    public static function configuredPath(string $configured, string $errorCode): string
    {
        if (self::hasControlCharacters($configured)) {
            throw new InvalidArgumentException($errorCode);
        }

        $value = trim(string: $configured, characters: ' ');
        $segments = trim(string: $value, characters: "/\\");

        if ($segments === '') {
            return '/';
        }

        if (!str_starts_with($value, '/')) {
            return '/' . $segments . '/';
        }

        return '/' . $segments . (str_ends_with($value, '/') ? '/' : '');
    }

    /** An absolute http or https URL, or null; see AbsoluteUrl::from() for what is refused. */
    public static function parse(string $url): ?AbsoluteUrl
    {
        return AbsoluteUrl::from($url);
    }

    /** The host a Host header value names, lower-cased; null for anything but host[:port]. */
    public static function host(string $header): ?string
    {
        $url = AbsoluteUrl::from('http://' . $header);
        if ($url === null || $url->path !== '' || $url->query !== null || $url->fragment !== null) {
            return null;
        }

        return $url->host;
    }

    private static function hasControlCharacters(string $value): bool
    {
        return preg_match(self::CONTROL_CHARACTERS, $value) === 1;
    }
}
