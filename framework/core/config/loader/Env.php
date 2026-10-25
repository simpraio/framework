<?php

declare(strict_types=1);

namespace core\config\loader;

final readonly class Env
{
    /**
     * @param array<string, string> $map env variable name => target config path (dotted)
     */
    public function __construct(
        private array $map,
    ) {}

    /**
     * Empty environment values do not override other layers.
     *
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $result = [];

        foreach ($this->map as $name => $path) {
            $raw = getenv($name);
            if ($raw === false || $raw === '') {
                continue;
            }
            self::assign($result, $path, $raw);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $target
     */
    private static function assign(array &$target, string $path, string $value): void
    {
        $ref = &$target;
        foreach (explode('.', $path) as $key) {
            if (!array_key_exists($key, $ref) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            /** @var array<string, mixed> $tmp */
            $tmp = &$ref[$key];
            $ref = &$tmp;
        }
        $ref = $value;
    }
}
