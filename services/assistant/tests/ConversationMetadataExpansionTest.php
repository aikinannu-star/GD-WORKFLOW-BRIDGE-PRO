<?php

require_once __DIR__ . '/../RuntimeBootstrap.php';

$bootstrap = RuntimeBootstrap::bootstrap();
$runtime = $bootstrap['runtime'];
$conversationManager = $runtime->conversationManager;
$eventEmitter = $runtime->eventEmitter;

$events = [];
$eventEmitter->on('conversation.created', function (array $payload) use (&$events): void {
    $events[] = ['event' => 'conversation.created', 'conversationId' => $payload['conversationId'] ?? null];
});
$eventEmitter->on('conversation.updated', function (array $payload) use (&$events): void {
    $events[] = ['event' => 'conversation.updated', 'conversationId' => $payload['conversationId'] ?? null];
});

$conversationId = 'metadata-expansion-' . uniqid();
$session = $conversationManager->createSession($conversationId, [
    'assistantId' => 'support-assistant',
    'tenantId' => 'tenant-1',
    'title' => 'Account recovery',
    'tags' => ['support', 'billing'],
    'status' => 'active',
    'model' => 'llama3.2',
    'participants' => ['user-123'],
], 'tenant-1', 'user-123');

if (empty($session['metadata']['title']) || $session['metadata']['title'] !== 'Account recovery') {
    echo 'Conversation title was not preserved' . PHP_EOL;
    exit(1);
}

if (($session['metadata']['messageCount'] ?? 0) !== 0) {
    echo 'Initial message count should start at zero' . PHP_EOL;
    exit(1);
}

$conversationManager->appendMessage($conversationId, [
    'role' => 'user',
    'content' => 'I need help resetting my password',
    'timestamp' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
]);

$stored = $conversationManager->getSession($conversationId);
if (($stored['metadata']['messageCount'] ?? 0) !== 1) {
    echo 'Message count was not updated after append' . PHP_EOL;
    exit(1);
}

$metadata = new ConversationMetadata($stored['metadata']);
$metadata->recordTokenUsage(120, 45, 'llama3.2', 0.0023);
$metadata->recordToolCall('workflow.execute');
$metadata->recordWorkflowExecution('refund-request');
$metadata->setTitle('Account recovery follow-up');
$metadata->addTag('urgent');

$updated = $conversationManager->addMetadata($conversationId, $metadata->toArray());
$merged = $updated['metadata'] ?? [];

if (($merged['totalTokens'] ?? 0) !== 165) {
    echo 'Token totals were not recorded correctly' . PHP_EOL;
    exit(1);
}

if (($merged['toolCalls'] ?? 0) !== 1) {
    echo 'Tool call count was not recorded correctly' . PHP_EOL;
    exit(1);
}

if (($merged['workflowExecutions'] ?? 0) !== 1) {
    echo 'Workflow execution count was not recorded correctly' . PHP_EOL;
    exit(1);
}

if (!in_array('urgent', $merged['tags'] ?? [], true)) {
    echo 'Tag was not persisted' . PHP_EOL;
    exit(1);
}

if ($merged['title'] !== 'Account recovery follow-up') {
    echo 'Title was not updated correctly' . PHP_EOL;
    exit(1);
}

if (count($events) < 2) {
    echo 'Expected lifecycle events to be emitted' . PHP_EOL;
    exit(1);
}

echo 'Conversation metadata expansion test passed' . PHP_EOL;
