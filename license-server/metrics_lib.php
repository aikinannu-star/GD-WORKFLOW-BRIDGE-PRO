<?php
// Simple metrics library backed by a JSON file (data/metrics.json).

function _metrics_file(): string {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir . '/metrics.json';
}

function inc_metric(string $name, int $by = 1): void {
    $pushgateway = getenv('PUSHGATEWAY_URL');
    $backend = getenv('LICENSE_METRICS_BACKEND') ?: '';
    if ($pushgateway !== false && !empty($pushgateway) && strtolower($backend) !== 'file') {
        // Use Pushgateway as authoritative backend. Fetch existing metrics for job/instance,
        // increment the single metric, and push full snapshot.
        $job = getenv('PUSHGATEWAY_JOB') ?: 'license_server';
        $instance = getenv('PUSHGATEWAY_INSTANCE') ?: gethostname();
        $url = rtrim($pushgateway, '/') . '/metrics/job/' . rawurlencode($job) . '/instance/' . rawurlencode($instance);
        $text = false;
        if (function_exists('curl_version')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            $text = curl_exec($ch);
            curl_close($ch);
        } else {
            $text = @file_get_contents($url);
        }
        $metrics = [];
        if ($text !== false && $text !== null) {
            $metrics = parse_prometheus_text($text);
        }
        if (!isset($metrics[$name])) $metrics[$name] = 0;
        $metrics[$name] += $by;
        // Push full snapshot
        $ok = pushgateway_push($metrics);
        // Append history snapshot locally for time-series UI regardless of push success
        $dir = __DIR__ . '/data'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $histFile = $dir . '/metrics_history.log';
        $entry = ['ts' => date('c'), 'metrics' => $metrics];
        @file_put_contents($histFile, json_encode($entry) . PHP_EOL, FILE_APPEND | LOCK_EX);
        return;
    }

    // Fallback to local file-backed metrics
    $file = _metrics_file();
    $data = [];
    if (file_exists($file)) {
        $contents = @file_get_contents($file);
        $data = json_decode($contents, true) ?: [];
    }
    if (!isset($data[$name])) $data[$name] = 0;
    $data[$name] += $by;
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function get_metrics(): array {
    $file = _metrics_file();
    if (!file_exists($file)) return [];
    $contents = @file_get_contents($file);
    return json_decode($contents, true) ?: [];
}

function metrics_to_prometheus_text(array $metrics): string {
    $out = "# HELP jwks_custom_metrics Custom JWKS metrics\n";
    $out .= "# TYPE jwks_custom_metrics gauge\n"; // generic
    foreach ($metrics as $k => $v) {
        // sanitize metric name
        $mn = preg_replace('/[^a-zA-Z0-9_:]/', '_', $k);
        $out .= "{$mn} {$v}\n";
    }
    return $out;
}

function pushgateway_push(array $metrics): bool {
    $url_base = rtrim(getenv('PUSHGATEWAY_URL') ?: '', '/');
    if (empty($url_base)) return false;
    $job = getenv('PUSHGATEWAY_JOB') ?: 'license_server';
    $instance = getenv('PUSHGATEWAY_INSTANCE') ?: gethostname();
    $url = $url_base . '/metrics/job/' . rawurlencode($job) . '/instance/' . rawurlencode($instance);
    $body = metrics_to_prometheus_text($metrics);

    // Use curl when available
    if (function_exists('curl_version')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300);
    }

    // Fallback to file_get_contents with stream context
    $opts = [
        'http' => [
            'method' => 'PUT',
            'header' => "Content-Type: text/plain\r\n",
            'content' => $body,
            'timeout' => 5
        ]
    ];
    $context = stream_context_create($opts);
    $res = @file_get_contents($url, false, $context);
    // No direct status code available here — assume success if no false
    return ($res !== false);
}

function parse_prometheus_text(string $text): array {
    $lines = preg_split('/\r?\n/', $text);
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (preg_match('/^([a-zA-Z0-9_:]+)\s+([0-9eE+\-.]+)$/', $line, $m)) {
            $out[$m[1]] = $m[2] + 0;
        }
    }
    return $out;
}
