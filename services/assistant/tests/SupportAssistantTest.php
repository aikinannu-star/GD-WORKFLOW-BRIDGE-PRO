<?php

require_once __DIR__ . '/../RuntimeBootstrap.php';

$runtime = RuntimeBootstrap::bootstrap(['dispatcher_plugins_path' => __DIR__ . '/../../dispatcher/plugins']);
$toolRegistry = $runtime['toolRegistry'];
$assistantManager = $runtime['assistantManager'];

if (!$toolRegistry->has('dispatcher_action')) {
    fwrite(STDERR, "Dispatcher action tool was not registered\n");
    exit(1);
}

if (!$toolRegistry->has('workflow_execute')) {
    fwrite(STDERR, "Workflow execution tool was not registered\n");
    exit(1);
}

if (!in_array('support-assistant', $assistantManager->listAssistants(), true)) {
    fwrite(STDERR, "Support assistant was not registered\n");
    exit(1);
}

$result = $assistantManager->handle('support-assistant', [
    'message' => 'Please log this action and respond.',
    'conversationId' => 'test-conv',
    'sessionId' => 'sess-1',
    'tenantId' => 'default',
    'userId' => 'tester',
]);

if (empty($result['success']) || empty($result['assistantText'])) {
    fwrite(STDERR, "Support assistant did not return a successful response\n");
    fwrite(STDERR, "Result: " . json_encode($result) . "\n");
    exit(1);
}

fwrite(STDOUT, "Support assistant end-to-end test passed\n");
