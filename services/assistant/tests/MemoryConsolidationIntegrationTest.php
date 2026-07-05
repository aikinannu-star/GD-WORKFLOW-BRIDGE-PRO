<?php

require_once __DIR__ . '/../memory/MemoryStore.php';
require_once __DIR__ . '/../memory/MemoryRecord.php';
require_once __DIR__ . '/../memory/FileMemoryRepository.php';
require_once __DIR__ . '/../memory/MemoryConsolidationService.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        echo "FAIL: {$message}\n";
        exit(1);
    }
}

$repository = new FileMemoryRepository(__DIR__ . '/../data/assistant/test-consolidation-memory');
$store = new MemoryStore($repository);
$consolidationService = new MemoryConsolidationService($store);

$oldMemory = new MemoryRecord([
    'tenantId' => 'tenant-1',
    'userId' => 'user-42',
    'type' => 'fact',
    'content' => 'Lives in London',
    'confidence' => 0.9,
    'tags' => ['location'],
    'metadata' => ['source' => 'memory'],
]);
$newMemory = new MemoryRecord([
    'tenantId' => 'tenant-1',
    'userId' => 'user-42',
    'type' => 'fact',
    'content' => 'Lives in Manchester',
    'confidence' => 0.95,
    'tags' => ['location'],
    'metadata' => ['source' => 'memory'],
]);

$store->add($oldMemory);
$store->add($newMemory);
$results = $consolidationService->consolidate('user-42', 'tenant-1');

assertTrue(count($results) === 1, 'consolidation should reduce duplicate facts to a single active memory');
$merged = $results[0];
assertTrue($merged->content === 'Lives in Manchester', 'consolidation should preserve the latest fact content');
assertTrue(($merged->metadata['supersededById'] ?? null) === null, 'latest memory should not be marked as superseded');
assertTrue(($merged->metadata['lineage'] ?? null) !== null, 'consolidated memory should retain lineage metadata');

echo 'Memory consolidation integration test passed' . PHP_EOL;
