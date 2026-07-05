<?php

require_once __DIR__ . '/../AssistantPipeline.php';
require_once __DIR__ . '/../AssistantContext.php';
require_once __DIR__ . '/../ToolRegistry.php';
require_once __DIR__ . '/../memory/MemoryRecord.php';
require_once __DIR__ . '/../memory/FileMemoryRepository.php';
require_once __DIR__ . '/../memory/MemoryStore.php';
require_once __DIR__ . '/../../dispatcher/events/RuntimeEventEmitter.php';

class MemoryRuntimeIntegrationTestProvider implements ModelProviderInterface
{
    public function chat(string $prompt, array $options = []): array
    {
        return ['success' => true, 'text' => 'ok', 'raw' => ['prompt' => $prompt]];
    }

    public function stream(string $prompt, array $options = []): iterable
    {
        yield ['success' => true, 'text' => 'ok', 'raw' => ['prompt' => $prompt]];
    }

    public function embeddings(string $input, array $options = []): array
    {
        return ['success' => true, 'data' => []];
    }

    public function health(): array
    {
        return ['success' => true];
    }

    public function capabilities(): array
    {
        return [
            'chat' => true,
            'embeddings' => false,
        ];
}

$repository = new FileMemoryRepository(__DIR__ . '/../data/assistant/test-runtime-memory');
$store = new MemoryStore($repository);
$store->add(new MemoryRecord([
    'tenantId' => 'tenant-1',
    'userId' => 'user-42',
    'type' => 'preference',
    'content' => 'User prefers email updates',
    'confidence' => 0.92,
    'tags' => ['preference'],
    'metadata' => ['source' => 'memory'],
]));

$pipeline = new AssistantPipeline(new ToolRegistry(), new MemoryRuntimeIntegrationTestProvider(), new RuntimeEventEmitter(), $store);
$context = new AssistantContext('support-assistant', 'conv-runtime-1', 'sess-runtime-1', 'tenant-1', 'user-42');
$result = $pipeline->execute($context, 'Please remember that I like email updates');
$prompt = $result['raw']['raw']['prompt'] ?? '';

if (strpos($prompt, 'User prefers email updates') === false) {
    echo 'Expected memory to be injected into the prompt' . PHP_EOL;
    exit(1);
}

echo 'Memory runtime integration test passed' . PHP_EOL;
