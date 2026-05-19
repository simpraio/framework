<?php

declare(strict_types=1);

namespace extensions\translation;

use core\config\Config as CoreConfig;
use core\tools\Format;

final class Translation
{
    /** @param array<string, scalar> $values */
    public static function text(string $pathId, string $id, string $language, array $values = []): string
    {
        Config::enabled();

        return Format::escape(self::render($pathId, $id, $language, $values));
    }

    /** @param array<string, scalar> $values */
    public static function html(string $pathId, string $id, string $language, array $values = []): string
    {
        Config::enabled();

        $safe = array_map(Format::escape(...), $values);
        return self::render($pathId, $id, $language, $safe);
    }

    public static function clear(string $pathId, ?string $language = null): void
    {
        Config::enabled();

        if ($language !== null) {
            Store::clear($pathId, $language);
            return;
        }

        $lang = CoreConfig::language();
        foreach ([$lang->default, ...$lang->available] as $code) {
            Store::clear($pathId, $code);
        }
    }

    /** @param array<string, scalar> $values */
    private static function render(string $pathId, string $id, string $language, array $values): string
    {
        $key = strtoupper($id);
        $tokens = Store::tokens($pathId, $language);
        $text = $tokens[$key] ?? $key;

        return $values === [] ? $text : Format::template($text, $values);
    }
}
