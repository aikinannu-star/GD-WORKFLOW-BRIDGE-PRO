<?php
require_once __DIR__ . '/../retry/RetryPolicy.php';
require_once __DIR__ . '/../retry/RetryEngine.php';
require_once __DIR__ . '/../deadletter/DeadLetterQueue.php';
require_once __DIR__ . '/../repositories/FileExecutionRepository.php';

$policy = new RetryPolicy(3, 0.0, true);
$engine = new RetryEngine($policy);
if (!$engine->shouldRetry('temporary_error', 1)) {
    fwrite(STDERR, "Expected retry engine to allow first retry" . PHP_EOL);
    exit(1);
}
if ($engine->shouldRetry('temporary_error', 3)) {
    fwrite(STDERR, "Expected retry engine to stop after max attempts" . PHP_EOL);
    exit(1);
}

$repo = new FileExecutionRepository(__DIR__ . '/../tmp-tests-executions');
$record = $repo->save([
    'workflowId' => 'wf-retry',
    'tenantId' => 'tenant-a',
    'status' => 'failed',
    'error' => 'temporary_error',
    'retryCount' => 2,
    'triggerSource' => 'scheduler',
]);
if (($record['retryCount'] ?? null) !== 2) {
    fwrite(STDERR, "Expected execution repository to persist retry count" . PHP_EOL);
    exit(1);
}

$dq = new DeadLetterQueue(__DIR__ . '/../tmp-tests-dlq');
$id = $dq->enqueue([
    'workflowId' => 'wf-retry',
    'executionId' => $record['executionId'],
    'action' => 'log',
    'error' => 'temporary_error',
]);
if (empty($id)) {
    fwrite(STDERR, "Expected dead-letter queue to return an identifier" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Reliability layer test passed" . PHP_EOL);
