<?php

declare(strict_types=1);

namespace core\config\loader;

use core\Paths;

final readonly class Files
{
    private const string LOCAL_FILENAME = 'framework.local.php';

    public function __construct(private Paths $paths)
    {
    }

    /**
     * A flattened project keeps framework.local.php in config/; it and its variants such as
     * framework.local.example.php are local configuration, never defaults, so they are not
     * compiled into the bundle.
     *
     * @return list<string> absolute paths to all framework default config files
     */
    public function configFiles(): array
    {
        $found = glob($this->paths->config . '/*.php');
        if ($found === false) {
            return [];
        }
        $found = array_filter(
            $found,
            static fn(string $path): bool => preg_match('/^framework\.local(\..+)?\.php$/', basename($path)) !== 1,
        );
        sort($found);
        return array_values($found);
    }

    /**
     * @return string|null absolute path to the local override file, or null if none exists
     */
    public function localFile(): ?string
    {
        $candidates = [
            $this->paths->config . '/' . self::LOCAL_FILENAME,
            $this->paths->base . '/' . self::LOCAL_FILENAME,
        ];

        return array_find($candidates, static fn($path) => is_file($path));
    }
}
