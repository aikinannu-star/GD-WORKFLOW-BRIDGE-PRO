<?php
/**
 * Lightweight Redis subscriber for gateway cache invalidation events.
 * Run this from the gateway host to log and emit simple metrics when CMS
 * publishes invalidation messages on `gateway:cms:auth:invalidate`.
 */

require_once __DIR__ . '/../../lib/ServiceHelpers.php';
require_once __DIR__ . '/../../lib/Metrics.php';

$host = $_ENV['GATEWAY_REDIS_HOST'] ?? '127.0.0.1';
$port = (int)($_ENV['GATEWAY_REDIS_PORT'] ?? 6379);

if (!class_exists('Redis')) {
    fwrite(STDERR, "Redis extension not available\n");
    exit(1);
}

// Create a dedicated Redis client for pub/sub
$r = new Redis();
try {
    $r->connect($host, $port, 1.0);
} catch (Throwable $e) {
    fwrite(STDERR, "Failed to connect to Redis at {$host}:{$port}\n");
    exit(1);
}

echo "Subscribed to gateway:cms:auth:invalidate on {$host}:{$port}\n";

$metrics = new Metrics();

$r->subscribe(['gateway:cms:auth:invalidate'], function ($redis, $chan, $msg) use ($metrics) {
    $now = gmdate('c');
    $decoded = json_decode($msg, true) ?: ['raw' => $msg];
    $log = ['time' => $now, 'channel' => $chan, 'message' => $decoded];
    // append to gateway log for later inspection
    $path = __DIR__ . '/../../services/data/gateway_invalidation.log';
    file_put_contents($path, json_encode($log, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);

    // increment metrics: total invalidations and per-action (if provided)
    try {
        $metrics->incr('gateway_invalidation_total', 1);
        $action = $decoded['action'] ?? ($decoded['message']['action'] ?? null);
        if ($action) {
            $san = preg_replace('/[^a-z0-9_]/', '_', strtolower($action));
            $metrics->incr('gateway_invalidation_action_' . $san, 1);
        }
    } catch (Throwable $e) {
        // metrics best-effort; ignore
    }

    // also print a concise line to stdout for supervision
    echo "[{$now}] INVALIDATE user=" . ($decoded['user_id'] ?? ($decoded['user'] ?? '')) . " project=" . ($decoded['project_id'] ?? ($decoded['project'] ?? '')) . " action=" . ($decoded['action'] ?? '') . "\n";
});

exit(0);
