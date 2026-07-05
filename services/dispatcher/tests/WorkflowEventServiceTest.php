<?php
require_once __DIR__ . '/../services/WorkflowEventService.php';

$service = new WorkflowEventService();
$received = [];
$service->subscribe('workflow.triggered', function (array $payload) use (&$received) {
    $received[] = $payload;
    return ['ok' => true, 'payload' => $payload];
});

$result = $service->publish('workflow.triggered', ['workflowId' => 'wf-test-1']);

if (count($result) !== 1 || empty($received)) {
    fwrite(STDERR, "Expected one subscriber invocation" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "WorkflowEventService test passed" . PHP_EOL);
