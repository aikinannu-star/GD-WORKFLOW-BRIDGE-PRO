<?php
require_once __DIR__ . '/../services/WorkflowExecutionService.php';

$workflow = [
    'id' => 'wf-test-1',
    'tenantId' => 'tenant-a',
    'name' => 'Test Workflow',
    'status' => 'published',
    'version' => 1,
    'workflow' => [
        'workflow_id' => 'wf-test-1',
        'name' => 'Test Workflow',
        'version' => '1',
        'steps' => [
            ['id' => 'start', 'type' => 'trigger', 'name' => 'Start', 'next' => ['log_step']],
            ['id' => 'log_step', 'type' => 'action', 'name' => 'Log Input', 'settings' => ['action_type' => 'log'], 'next' => ['end_step']],
            ['id' => 'end_step', 'type' => 'end', 'name' => 'End']
        ]
    ]
];

$service = new WorkflowExecutionService();
$result = $service->execute($workflow, ['message' => 'hello']);

if (($result['status'] ?? null) !== 'completed') {
    fwrite(STDERR, "Expected completed status, got: " . var_export($result, true) . PHP_EOL);
    exit(1);
}

if (count($result['steps'] ?? []) < 3) {
    fwrite(STDERR, "Expected at least three executed steps" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "WorkflowExecutionService test passed" . PHP_EOL);
