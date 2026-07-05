<?php

require_once __DIR__ . '/../AssistantPipeline.php';
require_once __DIR__ . '/../AssistantContext.php';
require_once __DIR__ . '/../ToolRegistry.php';
require_once __DIR__ . '/../memory/MemoryStore.php';
require_once __DIR__ . '/../memory/MemoryRecord.php';
require_once __DIR__ . '/../memory/FileMemoryRepository.php';
require_once __DIR__ . '/../memory/MemoryRetrievalService.php';
require_once __DIR__ . '/../../dispatcher/events/RuntimeEventEmitter.php';

class ContextAssemblyPromptCaptureProvider implements ModelProviderInterface
{
    public string $lastPrompt = '';

    public function chat(string $prompt, array $options = []): array
    {
        $this->lastPrompt = $prompt;
        return ['success' => true, 'text' => 'ok'];
    }

    public function stream(string $prompt, array $options = []): iterable
    {
        yield ['success' => true, 'text' => 'ok'];
    }

    public function embeddings(string $input, array $options = []): array
    {
        return ['vector' => []];
    }

    public function health(): array
    {
        return ['success' => true];
    }

    public function capabilities(): array
    {
        return ['chat' => true, 'embeddings' => false];
    }
}

$provider = new ContextAssemblyPromptCaptureProvider();
$eventEmitter = new RuntimeEventEmitter();
$toolRegistry = new ToolRegistry();
$memoryRepository = new FileMemoryRepository(__DIR__ . '/../data/assistant/test-context-memory');
$memoryStore = new MemoryStore($memoryRepository);
$memoryStore->add(new MemoryRecord([
    'tenantId' => 'tenant-1',
    'userId' => 'user-42',
    'type' => 'preference',
    'content' => 'User prefers email updates',
    'confidence' => 0.9,
    'tags' => ['preference'],
    'metadata' => ['source' => 'memory'],
]));

$context = new AssistantContext('support-assistant', 'conv-ctx-1', 'session-ctx-1', 'tenant-1', 'user-42');
$context->metadata['summary'] = 'The user is planning a launch.';
$context->metadata['conversation_history'] = [
    'User: I am preparing a launch plan.',
    'Assistant: I can help organize it.',
];
$context->set('workflow_state', ['status' => 'in_progress', 'current_step' => 'draft']);
$context->set('documents', [['title' => 'Launch checklist', 'content' => 'Complete launch checklist']]);

$pipeline = new AssistantPipeline($toolRegistry, $provider, $eventEmitter, $memoryStore);
$pipeline->execute($context, 'Please help me finish the launch plan.');

$prompt = $provider->lastPrompt;
if (strpos($prompt, 'Recent conversation') === false) {
    echo "FAIL: expected conversation context to be assembled" . PHP_EOL;
    exit(1);
}
if (strpos($prompt, 'Summary') === false) {
    echo "FAIL: expected summary context to be assembled" . PHP_EOL;
    exit(1);
}
if (strpos($prompt, 'Relevant memories') === false) {
    echo "FAIL: expected memory context to be assembled" . PHP_EOL;
    exit(1);
}
if (strpos($prompt, 'Workflow state') === false) {
    echo "FAIL: expected workflow context to be assembled" . PHP_EOL;
    exit(1);
}
if (strpos($prompt, 'Launch checklist') === false) {
    echo "FAIL: expected document context to be assembled" . PHP_EOL;
    exit(1);
}

echo 'Context assembly integration test passed' . PHP_EOL;
