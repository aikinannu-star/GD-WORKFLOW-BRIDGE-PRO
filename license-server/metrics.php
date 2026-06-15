<?php
// Prometheus-compatible metrics endpoint (very small). Exposes metrics written by other server actions.
header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
require_once __DIR__ . '/metrics_lib.php';
$pushgateway = getenv('PUSHGATEWAY_URL');
if ($pushgateway !== false && !empty($pushgateway)) {
    // Try to fetch metrics directly from pushgateway for this job/instance
    $job = getenv('PUSHGATEWAY_JOB') ?: 'license_server';
    $instance = getenv('PUSHGATEWAY_INSTANCE') ?: gethostname();
    $url = rtrim($pushgateway, '/') . '/metrics/job/' . rawurlencode($job) . '/instance/' . rawurlencode($instance);
    // prefer curl
    $text = false;
    if (function_exists('curl_version')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $text = curl_exec($ch);
        curl_close($ch);
    } else {
        $text = @file_get_contents($url);
    }
    if ($text !== false && $text !== null) {
        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        echo $text;
        exit;
    }
}

// Fallback to local file-backed metrics
$metrics = get_metrics();
echo metrics_to_prometheus_text($metrics);
