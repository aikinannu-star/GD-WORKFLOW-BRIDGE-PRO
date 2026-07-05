<?php

require_once __DIR__ . '/../../lib/ServiceHelpers.php';
require_once __DIR__ . '/../../lib/GracefulShutdownManager.php';

$metricsPath = ServiceHelpers::dataPath('assistant', 'metrics.json');
if (file_exists($metricsPath)) {
    unlink($metricsPath);
}

$manager = new GracefulShutdownManager('assistant', 1);

if ($manager->isShuttingDown()) {
    fwrite(STDERR, "Shutdown manager should start idle\n");
    exit(1);
}

$manager->beginRequest();
if ($manager->getActiveRequestCount() !== 1) {
    fwrite(STDERR, "Active request count should increment when a request begins\n");
    exit(1);
}

$metricsOutput = ServiceHelpers::renderPrometheusMetrics('assistant');
if (strpos($metricsOutput, 'assistant_active_requests') === false) {
    fwrite(STDERR, "Assistant active request gauge should be exposed in Prometheus output\n");
    exit(1);
}

$manager->beginDrain();
if (!$manager->isDraining()) {
    fwrite(STDERR, "Shutdown manager should enter draining mode\n");
    exit(1);
}

if ($manager->canAcceptRequests()) {
    fwrite(STDERR, "Manager should not accept new requests while draining\n");
    exit(1);
}

$executed = false;
$manager->onShutdown(function () use (&$executed): void {
    $executed = true;
});

$manager->requestShutdown('SIGTERM');
if (!$manager->isShuttingDown()) {
    fwrite(STDERR, "Shutdown manager should mark shutdown requested\n");
    exit(1);
}

if ($executed) {
    fwrite(STDERR, "Shutdown callback should not run before the timeout elapses\n");
    exit(1);
}

$metricsOutput = ServiceHelpers::renderPrometheusMetrics('assistant');
if (strpos($metricsOutput, 'assistant_shutdown_in_progress') === false) {
    fwrite(STDERR, "Assistant shutdown in-progress gauge should be exposed in Prometheus output\n");
    exit(1);
}

$firstShutdownAt = $manager->getShutdownRequestedAt();
if ($firstShutdownAt === null) {
    fwrite(STDERR, "Shutdown request timestamp should be recorded\n");
    exit(1);
}

$manager->requestShutdown('duplicate');
if ($manager->getShutdownRequestedAt() !== $firstShutdownAt) {
    fwrite(STDERR, "Shutdown request should be idempotent and preserve original timestamp\n");
    exit(1);
}

$manager->beginRequest();
try {
    throw new RuntimeException('simulated failure');
} catch (RuntimeException $e) {
    // Simulate a handler that throws; endRequest is still called in finally.
} finally {
    $manager->endRequest();
}

if ($manager->getActiveRequestCount() !== 1) {
    fwrite(STDERR, "Exception-safe cleanup should preserve active request accounting\n");
    exit(1);
}

sleep(2);
if (!$manager->hasShutdownTimeoutExpired()) {
    fwrite(STDERR, "Shutdown timeout should have expired after sleep\n");
    exit(1);
}

$manager->handleShutdownTimeout();
if (!$executed) {
    fwrite(STDERR, "Shutdown callbacks should run once shutdown timeout is exceeded\n");
    exit(1);
}

$manager->endRequest();
if ($manager->getActiveRequestCount() !== 0) {
    fwrite(STDERR, "Active request count should decrement when remaining requests complete\n");
    exit(1);
}

fwrite(STDOUT, "Graceful shutdown coordination test passed\n");
