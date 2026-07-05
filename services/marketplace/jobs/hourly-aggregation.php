#!/usr/bin/env php
<?php
/**
 * Hourly Aggregation Job for Time Series Data
 * Should be executed hourly via cron: 0 * * * * php /path/to/hourly-aggregation.php
 * Or on Windows Task Scheduler
 */

require_once __DIR__ . '/../../lib/ServiceHelpers.php';
require_once __DIR__ . '/../TimeSeriesHelper.php';

// Log execution
$logFile = __DIR__ . '/../logs/timeseries-aggregation.log';
@mkdir(dirname($logFile), 0755, true);

$startTime = microtime(true);
$timestamp = gmdate('Y-m-d H:i:s');

// Rotate log weekly
if (filesize($logFile) > 10 * 1024 * 1024) {
    rename($logFile, $logFile . '.' . gmdate('Y-m-d'));
}

file_put_contents($logFile, "[$timestamp] Starting hourly aggregation\n", FILE_APPEND);

try {
    // Record hourly snapshot
    $helper = new TimeSeriesHelper();
    $snapshot = $helper->recordHourlySnapshot();
    
    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
    file_put_contents($logFile, "[$timestamp] Snapshot recorded: {$snapshot['tenant_count']} tenants, health={$snapshot['health_score']}, elapsed=${elapsed}ms\n", FILE_APPEND);
    
    // Prune data older than 90 days (weekly)
    if (gmdate('w') === '0') {  // Sunday
        $pruned = $helper->pruneOldData(90);
        file_put_contents($logFile, "[$timestamp] Pruned $pruned data points\n", FILE_APPEND);
    }
    
    file_put_contents($logFile, "[$timestamp] Aggregation complete\n", FILE_APPEND);
    
} catch (Exception $e) {
    file_put_contents($logFile, "[$timestamp] ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    error_log("Time series aggregation failed: " . $e->getMessage());
    exit(1);
}

exit(0);
