<?php

require_once __DIR__ . '/../memory/MemoryRecord.php';
require_once __DIR__ . '/../memory/FileMemoryRepository.php';
require_once __DIR__ . '/../memory/MemoryExtractor.php';
require_once __DIR__ . '/../memory/MemoryStore.php';

$repository = new FileMemoryRepository(__DIR__ . '/../data/assistant/test-memory');
$store = new MemoryStore($repository);
$extractor = new MemoryExtractor($repository);

$conversation = [
    'conversationId' => 'conv-memory-1',
    'tenantId' => 'tenant-1',
    'userId' => 'user-42',
    'history' => [
        ['role' => 'user', 'content' => 'I prefer email updates', 'timestamp' => '2026-01-01T00:00:00Z'],
        ['role' => 'assistant', 'content' => 'I can help with that', 'timestamp' => '2026-01-01T00:00:01Z'],
        ['role' => 'user', 'content' => 'My goal is to automate the billing workflow', 'timestamp' => '2026-01-01T00:00:02Z'],
    ],
];

$extracted = $extractor->extractFromConversation($conversation);
if (count($extracted) < 2) {
    echo 'Expected multiple memory records to be extracted' . PHP_EOL;
    exit(1);
}

$stored = $store->forUser('user-42', 'tenant-1');
if (count($stored) < 2) {
    echo 'Expected memory records to be stored for the user' . PHP_EOL;
    exit(1);
}

$search = $store->search('user-42', 'tenant-1', ['type' => 'preference']);
if (count($search) < 1) {
    echo 'Expected preference memory to be searchable' . PHP_EOL;
    exit(1);
}

$retrieved = $store->retrieve('user-42', 'tenant-1', 'email updates');
if (count($retrieved) < 1) {
    echo 'Expected relevant memories to be retrievable' . PHP_EOL;
    exit(1);
}

$record = $stored[0];
if ($record->content === '') {
    echo 'Stored memory content should not be empty' . PHP_EOL;
    exit(1);
}

$deleted = $store->delete($record->id);
if (!$deleted) {
    echo 'Expected memory deletion to succeed' . PHP_EOL;
    exit(1);
}

echo 'Memory subsystem test passed' . PHP_EOL;
