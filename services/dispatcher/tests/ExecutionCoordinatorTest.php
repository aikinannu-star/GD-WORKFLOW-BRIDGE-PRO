<?php
require_once __DIR__ . '/../coordination/ExecutionCoordinator.php';

$coordinator = new ExecutionCoordinator();
$workflowDefinition = [
    'workflow_id' => 'wf-coord',
    'name' => 'coordination workflow',
    'version' => 1,
    'steps' => [
        ['id' => 'trigger-1', 'type' => 'trigger', 'next' => ['end-1']],
        ['id' => 'end-1', 'type' => 'end'],
    ],
];
$queued = $coordinator->enqueueExecution(['id' => 'wf-coord', 'workflow' => $workflowDefinition], ['triggerSource' => 'test']);
if (empty($queued['queueId'])) {
    fwrite(STDERR, "Expected execution coordinator to queue workflow" . PHP_EOL);
    exit(1);
}

$processed = $coordinator->processNext();
if (($processed['status'] ?? null) !== 'processed') {
    fwrite(STDERR, "Expected execution coordinator to process queued workflow" . PHP_EOL);
    exit(1);
}

$metrics = $coordinator->getMetrics();
if (($metrics['execution.enqueued'] ?? 0) < 1 || ($metrics['execution.started'] ?? 0) < 1) {
    fwrite(STDERR, "Expected coordinator metrics to be recorded" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "ExecutionCoordinator test passed" . PHP_EOL);
