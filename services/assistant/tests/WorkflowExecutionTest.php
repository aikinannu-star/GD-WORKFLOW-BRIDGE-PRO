<?php

require_once __DIR__ . '/../RuntimeBootstrap.php';
require_once __DIR__ . '/../../dispatcher/repositories/FileWorkflowRepository.php';

$runtimeData = RuntimeBootstrap::bootstrap([
    'dispatcher_plugins_path' => __DIR__ . '/../../dispatcher/plugins',
]);
$runtime = $runtimeData['runtime'];

$workflowRepo = new FileWorkflowRepository();
$workflow = [
    'id' => 'default',
    'workflow_id' => 'default',
    'name' => 'Default Workflow',
    'version' => 1,
    'tenantId' => 'default',
    'steps' => [
        [
            'id' => 'trigger',
            'type' => 'trigger',
            'next' => ['log_action'],
        ],
        [
            'id' => 'log_action',
            'type' => 'action',
            'settings' => [
                'action_type' => 'log',
                'message' => 'Workflow executed successfully by assistant.',
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

$result = $runtime->assistantManager->handle('support-assistant', [
    'message' => 'Please execute workflow for me.',
    'conversationId' => 'test-conv',
    'sessionId' => 'test-session',
    'tenantId' => 'default',
    'userId' => 'tester',
]);

if (empty($result['success'])) {
    fwrite(STDERR, "Workflow execution failed: assistant did not succeed\n");
    fwrite(STDERR, json_encode($result, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

if (empty($result['toolResult']) || empty($result['toolResult']['success'])) {
    fwrite(STDERR, "Workflow execution tool failed\n");
    fwrite(STDERR, json_encode($result, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$toolResult = $result['toolResult']['result'] ?? null;
if (empty($toolResult) || ($toolResult['workflowId'] ?? '') !== 'default' || ($toolResult['status'] ?? '') !== 'completed') {
    fwrite(STDERR, "Unexpected workflow execution result\n");
    fwrite(STDERR, json_encode($result, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

fwrite(STDOUT, "Workflow execution test passed\n");
