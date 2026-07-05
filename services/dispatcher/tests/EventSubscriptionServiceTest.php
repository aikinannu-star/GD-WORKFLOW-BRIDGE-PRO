<?php
require_once __DIR__ . '/../services/WorkflowEventService.php';

$service = new WorkflowEventService();
$result = $service->publish('workflow.started', ['workflowId' => 'wf-test-1', 'tenantId' => 'tenant-a']);

if (empty($result)) {
    fwrite(STDERR, "Expected event bus to publish subscribers" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "EventSubscriptionService test passed" . PHP_EOL);
