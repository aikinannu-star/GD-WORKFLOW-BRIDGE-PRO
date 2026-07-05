<?php

require_once __DIR__ . '/../memory/MemoryPolicy.php';
require_once __DIR__ . '/../memory/MemoryRecord.php';
require_once __DIR__ . '/../memory/FileMemoryRepository.php';
require_once __DIR__ . '/../memory/MemoryStore.php';
require_once __DIR__ . '/../memory/MemoryExtractor.php';
require_once __DIR__ . '/../memory/MemoryExtractionListener.php';
require_once __DIR__ . '/../../dispatcher/events/RuntimeEventEmitter.php';

$repository = new FileMemoryRepository(__DIR__ . '/../data/assistant/test-listener-memory');
$store = new MemoryStore($repository);
$extractor = new MemoryExtractor($repository, new MemoryPolicy(), $store);
$listener = new MemoryExtractionListener($extractor, new MemoryPolicy());
$eventEmitter = new RuntimeEventEmitter();
$eventEmitter->on('conversation.completed', $listener);

$eventEmitter->emit('conversation.completed', [
    'conversationId' => 'conv-listener-1',
    'sessionId' => 'sess-listener-1',
    'tenantId' => 'tenant-1',
    'userId' => 'user-42',
    'session' => [
        'conversationId' => 'conv-listener-1',
        'sessionId' => 'sess-listener-1',
        'tenantId' => 'tenant-1',
        'userId' => 'user-42',
        'history' => [
            ['role' => 'user', 'content' => 'I prefer email updates', 'timestamp' => '2026-01-01T00:00:00Z'],
        ],
        'metadata' => ['status' => 'completed'],
    ],
]);

$memories = $store->forUser('user-42', 'tenant-1');
if (count($memories) < 1) {
    echo 'Expected automatic memory extraction to persist a memory record' . PHP_EOL;
    exit(1);
}

echo 'Memory extraction listener test passed' . PHP_EOL;
