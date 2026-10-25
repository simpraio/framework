<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests/root.php';
require __DIR__ . '/../../tests/helpers.php';

$server = startFrameworkTestServer('simpra-http-fixture');
$port = $server['port'];

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
