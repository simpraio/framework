<?php

/**
 * Pipeline micro-benchmark: cold (APCu cleared) vs warm (APCu populated).
 *
 * Starts a temporary php -S server, hits the benchmark endpoint for cold
 * and warm passes, and prints a comparison table.
 *
 * Usage: php tools/bench/pipeline.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../tests/root.php';

$php  = PHP_BINARY;
$port = 8097;
$host = "http://127.0.0.1:{$port}";
$root = SIMPRA_ROOT . '/public';

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

/** @var array{database?: array<string,mixed>, project?: array<string,mixed>, crypt?: array<string,mixed>} $local */
$local = require SIMPRA_LOCAL;
$db    = $local['database'] ?? [];
$env   = [
    'SIMPRA_DB_DRIVER'   => (string) ($db['driver']   ?? 'mysql'),
    'SIMPRA_DB_HOST'     => (string) ($db['hostname'] ?? '127.0.0.1'),
    'SIMPRA_DB_PORT'     => (string) ($db['port']     ?? 3306),
    'SIMPRA_DB_NAME'     => (string) ($db['database'] ?? ''),
    'SIMPRA_DB_USER'     => (string) ($db['username'] ?? ''),
    'SIMPRA_DB_PASS'     => (string) ($db['password'] ?? ''),
    'SIMPRA_CRYPT_KEY'   => (string) ($local['crypt']['key']   ?? $local['project']['crypt_key'] ?? 'test'),
    'SIMPRA_PROJECT_URL' => (string) ($local['project']['url'] ?? 'http://127.0.0.1'),
    'SystemRoot'         => getenv('SystemRoot') ?: 'C:\\Windows',
    'PATH'               => getenv('PATH') ?: '',
];

$proc = proc_open(
    [$php, '-S', "127.0.0.1:{$port}", '-t', $root],
    $descriptors,
    $pipes,
    null,
    $env,
);

if (!is_resource($proc)) {
    fwrite(STDERR, "Failed to start PHP server\n");
    exit(1);
}

fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

// Wait until the server is accepting connections.
$ready = false;
for ($i = 0; $i < 40; $i++) {
    usleep(100_000);
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
    if ($fp) {
        fclose($fp);
        $ready = true;
        break;
    }
}

if (!$ready) {
    fwrite(STDERR, "Server on :{$port} did not start in time\n");
    proc_terminate($proc);
    exit(1);
}

function fetch_bench(string $host, string $pass): array
{
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $raw = file_get_contents("{$host}/bench.php?pass={$pass}", false, $ctx);
    if ($raw === false) {
        fwrite(STDERR, "Request failed for pass={$pass}\n");
        return [];
    }
    return json_decode($raw, true) ?? [];
}

// Prime the server with one normal request so APCu 'config' and 'extensions'
// are populated before the cold pass selectively evicts the other keys.
file_get_contents("{$host}/", false, stream_context_create(['http' => ['timeout' => 10]]));

/** @var array<string, float> $cold */
$cold = fetch_bench($host, 'cold');

/** @var array<string, float> $warm */
$warm = fetch_bench($host, 'warm');

proc_terminate($proc);
proc_close($proc);

if (!$cold || !$warm) {
    fwrite(STDERR, "No data received\n");
    exit(1);
}

$stages = array_keys($cold);
$labelW = max(array_map('strlen', $stages));
$colW   = 10;
$line   = str_repeat('─', $labelW + 2 + $colW * 3 + 8);

printf("\n%s\n", $line);
printf("  %-{$labelW}s  %{$colW}s  %{$colW}s  %{$colW}s\n", 'Stage', 'Cold (µs)', 'Warm (µs)', 'Saved (µs)');
printf("%s\n", $line);

foreach ($stages as $stage) {
    $c   = $cold[$stage]  ?? 0.0;
    $w   = $warm[$stage]  ?? 0.0;
    $d   = $c - $w;
    $tag = $stage === 'TOTAL' ? '  ◀' : ($d > 200 ? '  ★' : '');
    printf(
        "  %-{$labelW}s  %{$colW}.1f  %{$colW}.1f  %{$colW}.1f%s\n",
        $stage, $c, $w, $d, $tag,
    );
}

printf("%s\n\n", $line);
echo "  ★ = APCu saved >200 µs   ◀ = full request\n\n";
