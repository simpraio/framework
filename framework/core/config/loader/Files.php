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
     * @return list<string> absolute paths to all framework default config files
     */
    public function configFiles(): array
    {
        $found = glob($this->paths->config . '/*.php');
        if ($found === false) {
            return [];
        }
        // Sort for a deterministic merge order: glob() order is filesystem-dependent,
        // so two files defining the same key could otherwise resolve differently across
        // OS/filesystems (e.g. Windows dev vs Linux prod).
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
