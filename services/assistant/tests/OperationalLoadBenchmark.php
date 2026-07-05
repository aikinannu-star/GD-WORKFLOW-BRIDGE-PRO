<?php

require_once __DIR__ . '/../LocalModelProvider.php';
require_once __DIR__ . '/../OllamaProvider.php';

function percentile(array $values, int $percentile): float
{
    if ($values === []) {
        return 0.0;
    }

    sort($values);
    $index = (int)floor(($percentile / 100.0) * count($values));
    $index = max(0, min(count($values) - 1, $index));
    return (float)$values[$index];
}

$iterations = 100;
$provider = new LocalModelProvider();
$durations = [];

for ($i = 0; $i < $iterations; $i++) {
    $start = microtime(true);
    $provider->chat('benchmark');
    $durations[] = (microtime(true) - $start) * 1000.0;
}

$sum = array_sum($durations);
$avg = $sum / count($durations);
$min = min($durations);
$max = max($durations);
$p50 = percentile($durations, 50);
$p95 = percentile($durations, 95);
$p99 = percentile($durations, 99);
$reqPerSec = $iterations / max(0.001, $sum / 1000.0);

$failProvider = new OllamaProvider(['api_url' => 'http://127.0.0.1:1/v1/completions', 'timeout' => 1]);
$failureStart = microtime(true);
$failingResult = $failProvider->generate('benchmark');
$failureLatencyMs = (microtime(true) - $failureStart) * 1000.0;

fwrite(STDOUT, "Operational load benchmark\n");
fwrite(STDOUT, sprintf("iterations=%d req/sec=%.2f avg_ms=%.3f p50_ms=%.3f p95_ms=%.3f p99_ms=%.3f min_ms=%.3f max_ms=%.3f\n", $iterations, $reqPerSec, $avg, $p50, $p95, $p99, $min, $max));
fwrite(STDOUT, sprintf("failure_provider_latency_ms=%.3f success=%s health=%s\n", $failureLatencyMs, $failingResult['success'] ? 'true' : 'false', $failProvider->health()['status'] ?? 'unknown'));
