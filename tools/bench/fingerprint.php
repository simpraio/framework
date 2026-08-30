<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests/root.php';

$configFiles = [
    SIMPRA_ROOT . '/config/app.php',
];
$localFile = SIMPRA_LOCAL;
$iterations = 10_000;

function fingerprint(array $configFiles, ?string $localFile): string
{
    $paths = array_filter([...$configFiles, $localFile]);
    $stamp = implode(',', array_map(static fn(string $p) => $p . ':' . (int) filemtime($p), $paths));
    return hash('xxh3', $stamp);
}

function bench(string $label, int $iterations, callable $fn): void
{
    for ($i = 0; $i < 100; $i++) { $fn(); }

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) { $fn(); }
    $elapsed = hrtime(true) - $start;

    printf("%-38s total: %8.3f ms   per call: %8.4f µs\n",
        $label,
        $elapsed / 1_000_000,
        $elapsed / $iterations / 1_000,
    );
}

printf("Iterations: %d | OPcache: %s\n\n",
    $iterations,
    function_exists('opcache_get_status') && opcache_get_status() !== false ? 'enabled' : 'disabled',
);

// Isolate each part
bench('filemtime() × 1 (stat cached)',    $iterations, fn() => filemtime($configFiles[0]));
bench('filemtime() × 2 (stat cached)',    $iterations, fn() => (int) filemtime($configFiles[0]) . (int) filemtime($localFile));
bench('hash(xxh3) only',                  $iterations, fn() => hash('xxh3', 'path:/config/app.php:1234567890'));
bench('array overhead only (no filemtime)',$iterations, fn() => implode(',', array_map(static fn($p) => $p . ':0', [$configFiles[0], $localFile])));
bench('clearstatcache + filemtime × 1',   $iterations, function () use ($configFiles) { clearstatcache(); return filemtime($configFiles[0]); });
bench('clearstatcache + filemtime × 2',   $iterations, function () use ($configFiles, $localFile) { clearstatcache(); return (int) filemtime($configFiles[0]) . (int) filemtime($localFile); });
bench('full fingerprint()',               $iterations, fn() => fingerprint($configFiles, $localFile));
