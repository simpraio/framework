<?php

declare(strict_types=1);

namespace extensions\flash;

use core\Request;
use core\Session;
use core\config\Map;

final class Flash
{
    private const string KEY_ERRORS = '__flash_errors';
    private const string KEY_INPUT = '__flash_input';

    private static bool $loaded = false;

    /** @var array<string, string> */
    private static array $errors = [];

    /** @var array<string, mixed> */
    private static array $submitted = [];

    /**
     * Persist field errors and (optionally) selected request input across the
     * upcoming redirect, so the failed form can be re-rendered with the user's
     * values pre-filled.
     *
     * Input is opt-in via $keep: only listed field names are stored. Default
     * behavior persists nothing - sensitive fields like `password` cannot
     * leak to session storage by accident. The caller decides what's safe to
     * keep (typically: email, name, address - never passwords or tokens).
     *
     * @param array<string, string> $errors field name => error message
     * @param list<string> $keep request fields to persist verbatim
     */
    public static function withErrors(array $errors, array $keep = []): void
    {
        Config::enabled();

        Session::set(self::KEY_ERRORS, $errors);

        if ($keep === []) {
            Session::forget(self::KEY_INPUT);
            return;
        }

        Session::set(self::KEY_INPUT, array_intersect_key(Request::all(), array_flip($keep)));
    }

    /** @return array<string, string> */
    public static function errors(): array
    {
        Config::enabled();

        self::load();
        return self::$errors;
    }

    /** @return array<string, mixed> */
    public static function submitted(): array
    {
        Config::enabled();

        self::load();
        return self::$submitted;
    }

    public static function hasErrors(): bool
    {
        Config::enabled();

        self::load();
        return self::$errors !== [];
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!array_key_exists(session_name(), $_COOKIE)) {
            return;
        }

        /** @var mixed $errors */
        $errors = Session::pull(self::KEY_ERRORS, []);
        self::$errors = Map::strings($errors);

        /** @var mixed $submitted */
        $submitted = Session::pull(self::KEY_INPUT, []);
        self::$submitted = is_array($submitted) ? Map::stringKeyed($submitted) : [];
    }
}
