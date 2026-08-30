<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests/root.php';

$php = PHP_BINARY;

$root = dirname(__DIR__, 2);
$framework = SIMPRA_ROOT;
$bundleDir = sys_get_temp_dir() . '/simpra_http_bench_' . bin2hex(random_bytes(4));
mkdir($bundleDir, 0o700, true);
$serverLog = $bundleDir . '/server.log';

$port = random_int(40_001, 55_000);
// Array form keeps the process handle attached to PHP itself and passes each argument verbatim.
$cmd = [$php, '-S', '127.0.0.1:' . $port, '-t', $framework . '/public'];
$process = proc_open(
    $cmd,
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
    $pipes,
    $root,
    ['SIMPRA_BUNDLE_DIR' => $bundleDir] + envWithLocal($root),
);

if (!is_resource($process)) {
    fwrite(STDERR, "Cannot start PHP built-in server\n");
    exit(1);
}

register_shutdown_function(static function () use (&$process, &$pipes, $bundleDir): void {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    removeTree($bundleDir);
});

waitForServer($port);

$url = "http://127.0.0.1:{$port}/";
$cold = timedRequest($url);

$times = [];
for ($i = 0; $i < 50; $i++) {
    $times[] = timedRequest($url);
}

sort($times);
$p50 = percentile($times, 0.50);
$p95 = percentile($times, 0.95);
$min = $times[0];
$max = $times[count($times) - 1];

printf("cold_ms=%.3f\n", $cold);
printf("warm_min_ms=%.3f\n", $min);
printf("warm_p50_ms=%.3f\n", $p50);
printf("warm_p95_ms=%.3f\n", $p95);
printf("warm_max_ms=%.3f\n", $max);

if ($cold > 1000.0 || $p95 > 200.0) {
    fwrite(STDERR, "HTTP benchmark outside smoke thresholds\n");
    exit(1);
}

function waitForServer(int $port): void
{
    $deadline = microtime(true) + 5.0;
    do {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            return;
        }
        usleep(50_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('PHP built-in server did not start');
}

/** @return array<string, string> */
function envWithLocal(string $root): array
{
    $env = $_ENV;
    $localFile = SIMPRA_LOCAL;
    if (!is_file($localFile)) {
        return $env;
    }

    $local = require $localFile;
    if (!is_array($local)) {
        return $env;
    }

    $db = is_array($local['database'] ?? null) ? $local['database'] : [];
    $project = is_array($local['project'] ?? null) ? $local['project'] : [];

    $env['SIMPRA_DB_DRIVER'] = (string)($db['driver'] ?? 'mysql');
    $env['SIMPRA_DB_HOST'] = (string)($db['hostname'] ?? '127.0.0.1');
    $env['SIMPRA_DB_PORT'] = (string)($db['port'] ?? 3306);
    $env['SIMPRA_DB_NAME'] = (string)($db['database'] ?? '');
    $env['SIMPRA_DB_USER'] = (string)($db['username'] ?? '');
    $env['SIMPRA_DB_PASS'] = (string)($db['password'] ?? '');
    $env['SIMPRA_CRYPT_KEY'] = (string)($project['crypt_key'] ?? '');
    $env['SIMPRA_PROJECT_URL'] = (string)($project['url'] ?? 'http://127.0.0.1');

    return $env;
}

function timedRequest(string $url): float
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Host: simpra.io\r\nConnection: close\r\n",
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);

    $start = hrtime(true);
    $body = @file_get_contents($url, false, $context);
    $elapsed = (hrtime(true) - $start) / 1_000_000;

    if ($body === false || !str_contains($body, 'Hello Earth')) {
        throw new RuntimeException('Unexpected benchmark response');
    }

    return $elapsed;
}

/** @param list<float> $values */
function percentile(array $values, float $percentile): float
{
    $index = (int)ceil(count($values) * $percentile) - 1;
    return $values[max(0, min($index, count($values) - 1))];
}

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    @rmdir($dir);
}
