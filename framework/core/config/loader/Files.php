<?php

declare(strict_types=1);

namespace core\config\loader;

use core\Paths;
use core\tools\Silently;
use RuntimeException;

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
     * scandir, not glob: [ ] * ? in the install path are ordinary characters. Every install ships
     * config/app.php, so no file at all is a mistake, never an empty configuration.
     *
     * @return non-empty-list<string> absolute paths to all framework default config files
     * @throws RuntimeException when config/ cannot be listed or holds no default file
     */
    public function configFiles(): array
    {
        $directory = $this->paths->config;
        $names = Silently::call(static fn(): array|false => scandir($directory));
        if ($names === false) {
            throw new RuntimeException("Config: cannot list the config directory {$directory}.");
        }

        $found = [];
        foreach ($names as $name) {
            if (
                str_ends_with($name, '.php')
                && !str_starts_with($name, '.')
                && preg_match('/^framework\.local(\..+)?\.php$/D', $name) !== 1
                && is_file($directory . '/' . $name)
            ) {
                $found[] = $directory . '/' . $name;
            }
        }
        if ($found === []) {
            throw new RuntimeException(
                "Config: no default configuration file in {$directory}; every install ships config/app.php, "
                . 'so the directory is the wrong one or its files are missing.',
            );
        }

        sort($found, SORT_STRING);
        return $found;
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
