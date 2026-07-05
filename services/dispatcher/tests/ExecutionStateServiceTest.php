<?php
require_once __DIR__ . '/../services/ExecutionStateService.php';

$service = new ExecutionStateService();
$record = $service->start(['workflowId' => 'wf-test-1', 'tenantId' => 'tenant-a', 'status' => 'running']);
if (empty($record['executionId'])) {
    fwrite(STDERR, "Expected executionId to be generated" . PHP_EOL);
    exit(1);
}

$updated = $service->update($record['executionId'], ['status' => 'paused']);
if (($updated['status'] ?? null) !== 'paused') {
    fwrite(STDERR, "Expected execution status to be updated" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "ExecutionStateService test passed" . PHP_EOL);
