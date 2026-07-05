<?php
require_once __DIR__ . '/../services/WorkflowTriggerService.php';

$service = new WorkflowTriggerService();
$result = $service->trigger('wf-test-1', 'manual', ['source' => 'api']);

if (($result['trigger'] ?? null) !== 'manual') {
    fwrite(STDERR, "Expected manual trigger result" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "WorkflowTriggerService test passed" . PHP_EOL);
