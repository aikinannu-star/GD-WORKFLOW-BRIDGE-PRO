<?php

require_once __DIR__ . '/../RuntimeBootstrap.php';
require_once __DIR__ . '/../../dispatcher/repositories/FileWorkflowRepository.php';

// Bootstrap runtime
$runtimeData = RuntimeBootstrap::bootstrap([
    'dispatcher_plugins_path' => __DIR__ . '/../../dispatcher/plugins',
]);
$runtime = $runtimeData['runtime'];

$workflowRepo = new FileWorkflowRepository();
$workflow = [
    'id' => 'conditional',
    'workflow_id' => 'conditional',
    'name' => 'Conditional Workflow',
    'version' => 1,
    'tenantId' => 'default',
    'steps' => [
        [
            'id' => 'trigger',
            'type' => 'trigger',
            'next' => ['check'],
        ],
        [
            'id' => 'check',
            'type' => 'condition',
            'settings' => [
                'condition' => 'query == go_true',
                'true_next' => 'log_true',
                'false_next' => 'log_false',
            ],
            'next' => ['log_true','log_false'],
        ],
        [
            'id' => 'log_true',
            'type' => 'action',
            'settings' => [
                'action_type' => 'log',
                'message' => 'True branch executed',
            ],
            'next' => ['end'],
        ],
        [
            'id' => 'log_false',
            'type' => 'action',
            'settings' => [
                'action_type' => 'log',
                'message' => 'False branch executed',
            ],
            'next' => ['end'],
        ],
        [
            'id' => 'end',
            'type' => 'end',
        ],
    ],
];

$workflowRepo->save($workflow);

$events = [];
$runtime->eventEmitter->on('node.started', function($p) use (&$events) { $events[] = ['node.started', $p]; });
$runtime->eventEmitter->on('node.completed', function($p) use (&$events) { $events[] = ['node.completed', $p]; });
$runtime->eventEmitter->on('workflow.started', function($p) use (&$events) { $events[] = ['workflow.started', $p]; });
$runtime->eventEmitter->on('workflow.completed', function($p) use (&$events) { $events[] = ['workflow.completed', $p]; });

// Test true branch
$resultTrue = $runtime->assistantManager->handle('support-assistant', [
    'message' => 'execute workflow conditional go_true',
    'conversationId' => 'conv-1',
    'sessionId' => 'sess-1',
    'tenantId' => 'default',
    'userId' => 'tester',
]);

if (empty($resultTrue['success'])) {
    fwrite(STDERR, "Conditional test (true) failed: assistant did not succeed\n");
    fwrite(STDERR, json_encode($resultTrue, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$toolResult = $resultTrue['toolResult']['result'] ?? null;
if (empty($toolResult) || ($toolResult['workflowId'] ?? '') !== 'conditional' || ($toolResult['status'] ?? '') !== 'completed') {
    fwrite(STDERR, "Conditional test (true) failed: unexpected workflow result\n");
    fwrite(STDERR, json_encode($resultTrue, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$steps = array_column($toolResult['steps'], 'id');
if (!in_array('log_true', $steps, true)) {
    fwrite(STDERR, "Conditional test (true) failed: log_true was not executed\n");
    fwrite(STDERR, json_encode($toolResult['steps'], JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

// Clear events
$events = [];

// Test false branch
$resultFalse = $runtime->assistantManager->handle('support-assistant', [
    'message' => 'execute workflow conditional do_other',
    'conversationId' => 'conv-2',
    'sessionId' => 'sess-2',
    'tenantId' => 'default',
    'userId' => 'tester',
]);

if (empty($resultFalse['success'])) {
    fwrite(STDERR, "Conditional test (false) failed: assistant did not succeed\n");
    fwrite(STDERR, json_encode($resultFalse, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$toolResultF = $resultFalse['toolResult']['result'] ?? null;
if (empty($toolResultF) || ($toolResultF['workflowId'] ?? '') !== 'conditional' || ($toolResultF['status'] ?? '') !== 'completed') {
    fwrite(STDERR, "Conditional test (false) failed: unexpected workflow result\n");
    fwrite(STDERR, json_encode($resultFalse, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$stepsF = array_column($toolResultF['steps'], 'id');
if (!in_array('log_false', $stepsF, true)) {
    fwrite(STDERR, "Conditional test (false) failed: log_false was not executed\n");
    fwrite(STDERR, json_encode($toolResultF['steps'], JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

fwrite(STDOUT, "Conditional branching test passed\n");
