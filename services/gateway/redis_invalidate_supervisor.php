<?php
/**
 * Supervisor for the Redis invalidation subscriber.
 *
 * - Starts `redis_invalidate_subscriber.php` as a child process
 * - Restarts on crash with a short backoff
 * - Emits a JSON health file via ServiceHelpers::dataPath('gateway', 'invalidation_supervisor.json')
 * - Increments restart metric via `Metrics`
 */

require_once __DIR__ . '/../../lib/ServiceHelpers.php';
require_once __DIR__ . '/../../lib/Metrics.php';

$subscriberScript = __DIR__ . '/redis_invalidate_subscriber.php';
$healthPath = ServiceHelpers::dataPath('gateway', 'invalidation_supervisor.json');
$restartDelay = max(1, (int)($_ENV['GATEWAY_SUPERVISOR_RESTART_DELAY'] ?? 3));
$checkInterval = max(1, (int)($_ENV['GATEWAY_SUPERVISOR_CHECK_INTERVAL'] ?? 5));

$metrics = new Metrics();

$state = [
    'status' => 'idle',
    'pid' => null,
    'last_start' => null,
    'last_exit_code' => null,
    'restarts' => 0,
    'last_error' => null,
    'last_heartbeat' => null,
];

function writeHealth(array $s): void
{
    global $healthPath;
    @file_put_contents($healthPath, json_encode($s, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

function spawnSubscriber(): array
{
    global $subscriberScript;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($subscriberScript);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return [null, null, null];
    }
    $status = proc_get_status($process);
    $pid = $status['pid'] ?? null;
    // Close child stdout/stderr pipes to avoid blocking file descriptors
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    return [$process, $pipes, $pid];
}

$process = null;
$pipes = null;

register_shutdown_function(function () use (&$process) {
    if (is_resource($process)) {
        @proc_terminate($process);
        @proc_close($process);
    }
});

writeHealth($state);

while (true) {
    // If no process or process exited, spawn
    $running = false;
    if (is_resource($process)) {
        $st = proc_get_status($process);
        $running = !empty($st['running']);
    }

    if (!$running) {
        // If previous process existed, capture exit code
        if (is_resource($process)) {
            $exit = @proc_close($process);
            $state['last_exit_code'] = $exit;
            $state['status'] = 'exited';
            $state['pid'] = null;
            $state['last_heartbeat'] = gmdate('c');
            writeHealth($state);
            $process = null;
        }

        // Start a new subscriber process
        $state['last_start'] = gmdate('c');
        $state['status'] = 'starting';
        writeHealth($state);
        list($process, $pipes, $pid) = spawnSubscriber();
        if ($process && $pid) {
            $state['pid'] = $pid;
            $state['status'] = 'running';
            $state['restarts'] = ($state['restarts'] ?? 0) + 1;
            $state['last_heartbeat'] = gmdate('c');
            // metrics
            try { $metrics->incr('gateway_invalidation_supervisor_restarts', 1); } catch (Throwable $_) {}
            writeHealth($state);
        } else {
            $state['status'] = 'failed_to_spawn';
            $state['last_error'] = 'spawn_failed';
            writeHealth($state);
            sleep($restartDelay);
        }
    } else {
        // process running; update heartbeat and sleep
        $state['last_heartbeat'] = gmdate('c');
        writeHealth($state);
        sleep($checkInterval);
    }
}
