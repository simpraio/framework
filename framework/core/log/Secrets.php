<?php

declare(strict_types=1);

namespace core\log;

use SensitiveParameter;

/**
 * Short values are excluded to avoid redacting ordinary text.
 * Match all spellings together and merge overlaps; sequential replacements can leave secret fragments.
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
     * Keep only the next occurrence per spelling so memory does not grow with the number of matches.
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
     * Skip contained matches; only occurrences extending the redacted span need visiting.
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

    /** @return array{count: int} configured spellings, never secret values */
    public function __debugInfo(): array
    {
        return ['count' => count($this->forms)];
    }

    /** @return list<string> literal, URL and JSON spellings */
    private static function spellings(#[SensitiveParameter] string $value): array
    {
        $spellings = [$value, rawurlencode($value), urlencode($value)];
        foreach ([0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
            $encoded = json_encode($value, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded !== false) {
                $spellings[] = substr($encoded, offset: 1, length: -1);
            }
        }

        return $spellings;
    }
}
