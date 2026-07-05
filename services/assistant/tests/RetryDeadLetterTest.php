<?php

require_once __DIR__ . '/../RuntimeBootstrap.php';
require_once __DIR__ . '/../../dispatcher/repositories/FileWorkflowRepository.php';
require_once __DIR__ . '/../../dispatcher/deadletter/DeadLetterQueue.php';
require_once __DIR__ . '/../../dispatcher/services/ExecutionStateService.php';
require_once __DIR__ . '/../../dispatcher/actions/ActionInterface.php';
require_once __DIR__ . '/../../dispatcher/actions/ActionResult.php';
require_once __DIR__ . '/../../dispatcher/runtime/ExecutionContext.php';

$runtimeData = RuntimeBootstrap::bootstrap([
    'dispatcher_plugins_path' => __DIR__ . '/../../dispatcher/plugins',
]);
$runtime = $runtimeData['runtime'];

$workflowRepo = new FileWorkflowRepository();
$workflow = [
    'id' => 'retry-dead-letter',
    'workflow_id' => 'retry-dead-letter',
    'name' => 'Retry Dead Letter Workflow',
    'version' => 1,
    'tenantId' => 'default',
    'steps' => [
        [
            'id' => 'trigger',
            'type' => 'trigger',
            'next' => ['retry_action'],
        ],
        [
            'id' => 'retry_action',
            'type' => 'action',
            'settings' => [
                'action_type' => 'failing_action',
                'message' => 'This should fail repeatedly',
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

// Register a deliberately failing action so the workflow retries and then DLQs.
$runtime->registrar->registerAction('failing_action', new class implements ActionInterface {
    public function execute(array $payload, ExecutionContext $context): ActionResult {
        return ActionResult::failure('temporary_error', ['attempted' => true], true);
    }
});

$stateService = new ExecutionStateService();
$dlq = new DeadLetterQueue();

$events = [];
$runtime->eventEmitter->on('workflow.failed', function($payload) use (&$events) { $events[] = $payload; });
$runtime->eventEmitter->on('deadletter.created', function($payload) use (&$events) { $events[] = $payload; });

$result = $runtime->assistantManager->handle('support-assistant', [
    'message' => 'execute workflow retry-dead-letter',
    'conversationId' => 'conv-retry',
    'sessionId' => 'sess-retry',
    'tenantId' => 'default',
    'userId' => 'tester',
]);

if (empty($result['success'])) {
    fwrite(STDERR, "Retry/dead-letter test failed: assistant did not succeed\n");
    fwrite(STDERR, json_encode($result, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$toolResult = $result['toolResult']['result'] ?? null;
if (empty($toolResult) || ($toolResult['workflowId'] ?? '') !== 'retry-dead-letter' || ($toolResult['status'] ?? '') !== 'failed') {
    fwrite(STDERR, "Retry/dead-letter test failed: workflow did not end in failed state\n");
    fwrite(STDERR, json_encode($result, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$executionId = $toolResult['executionId'] ?? null;
if (!$executionId) {
    fwrite(STDERR, "Retry/dead-letter test failed: missing execution id\n");
    exit(1);
}

$execution = $stateService->get($executionId);
if (($execution['retryCount'] ?? 0) < 2) {
    fwrite(STDERR, "Retry/dead-letter test failed: retry count was not recorded\n");
    fwrite(STDERR, json_encode($execution) . "\n");
    exit(1);
}

$deadLetters = $dlq->list();
if (empty($deadLetters)) {
    fwrite(STDERR, "Retry/dead-letter test failed: dead-letter entry was not created\n");
    exit(1);
}

fwrite(STDOUT, "Retry/dead-letter test passed\n");
