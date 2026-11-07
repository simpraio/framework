<?php

declare(strict_types=1);

namespace core\log;

use SensitiveParameter;

/**
 * Replaces known secret values with [REDACTED] in logged text. Redacting by key cannot reach a secret
 * inside free text - an exception message carrying a DSN, a URL with a credential in its query.
 *
 * A value shorter than MIN_BYTES is left alone: it would match ordinary text and corrupt unrelated
 * lines. Each kept value is also matched as it is spelt inside a URL or a JSON string.
 *
 * All forms are matched together, in text order, and occurrences that overlap or touch become a single
 * [REDACTED]. Replacing one form after another - str_replace(), or strtr(), which
 * never matches inside text it has already replaced - leaves behind the part of a secret that overlaps
 * one replaced before it.
 */
final readonly class Secrets
{
    /** Written in place of caller text when Config::secrets() stopped at its limits: what it missed cannot be stripped. */
    public const string UNCOLLECTED = '[REDACTED: configured secrets could not be collected safely]';

    private const int MIN_BYTES = 8;

    /** @var list<string> */
    private array $forms;

    /** @param list<string> $values */
    public function __construct(#[SensitiveParameter] array $values)
    {
        $forms = [];
        foreach ($values as $value) {
            if (strlen($value) >= self::MIN_BYTES) {
                array_push($forms, ...self::spellings($value));
            }
        }
        $this->forms = array_values(array_unique($forms));
    }

    /**
     * Walks the text once, holding only the next occurrence of each form, so memory does not grow with
     * the number of matches: a known value repeated through megabytes of logged text must not exhaust it.
     */
    public function strip(string $text): string
    {
        /** @var array<int, int> $next */
        $next = array_filter(
            array_map(static fn(string $form): int|false => strpos($text, $form), $this->forms),
            static fn(int|false $at): bool => $at !== false,
        );

        $stripped = '';
        $copied = 0;
        while ($next !== []) {
            $start = min($next);
            $stripped .= substr($text, $copied, $start - $copied) . '[REDACTED]';
            $copied = $this->run($text, $start, $next);
        }

        return $stripped . substr($text, $copied);
    }

    /**
     * The end of the run of occurrences starting at $start, each overlapping or touching the one before,
     * with $next moved past it. An occurrence lying wholly inside the run so far is skipped, not visited.
     *
     * @param array<int, int> $next
     */
    private function run(string $text, int $start, array &$next): int
    {
        $end = $start;
        do {
            $extended = false;
            foreach ($next as $i => $at) {
                if ($at > $end) {
                    continue;
                }
                $length = strlen($this->forms[$i]);
                $end = max($end, $at + $length);
                $found = strpos($text, $this->forms[$i], max($at + 1, $end - $length + 1));
                if ($found === false) {
                    unset($next[$i]);
                } else {
                    $next[$i] = $found;
                }
                $extended = true;
            }
        } while ($extended);

        return $end;
    }

    /** @return array{count: int} the number of forms matched, never the values */
    public function __debugInfo(): array
    {
        return ['count' => count($this->forms)];
    }

    /** @return list<string> the value as it is, URL-encoded both ways, and escaped as a JSON string */
    private static function spellings(#[SensitiveParameter] string $value): array
    {
        $spellings = [$value, rawurlencode($value), urlencode($value)];
        foreach ([0, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
            $encoded = json_encode($value, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded !== false) {
                $spellings[] = substr($encoded, offset: 1, length: -1);
            }
        }

        return $spellings;
    }
}
