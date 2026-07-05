<?php

require_once __DIR__ . '/../memory/MemoryRecord.php';
require_once __DIR__ . '/../memory/MemoryRepositoryInterface.php';
require_once __DIR__ . '/../memory/MemoryStore.php';
require_once __DIR__ . '/../memory/MemoryLifecycleStageInterface.php';
require_once __DIR__ . '/../memory/MemoryLifecyclePipeline.php';

class TestMemoryRepository implements MemoryRepositoryInterface
{
    private array $records = [];

    public function save(MemoryRecord $record): MemoryRecord
    {
        if ($record->id === null) {
            $record->id = 'memory-' . count($this->records + 1);
        }

        $this->records[$record->id] = $record;
        return $record;
    }

    public function get(string $id): ?MemoryRecord
    {
        return $this->records[$id] ?? null;
    }

    public function delete(string $id): bool
    {
        if (!isset($this->records[$id])) {
            return false;
        }

        unset($this->records[$id]);
        return true;
    }

    public function deleteExpired(string $tenantId = 'default'): int
    {
        return 0;
    }

    public function listByUser(string $userId, string $tenantId = 'default'): array
    {
        return array_values(array_filter($this->records, function (MemoryRecord $record) use ($userId, $tenantId): bool {
            return $record->userId === $userId && $record->tenantId === $tenantId;
        }));
    }

    public function search(string $userId, string $tenantId, array $filters = []): array
    {
        return $this->listByUser($userId, $tenantId);
    }
}

class TestLifecycleStage implements MemoryLifecycleStageInterface
{
    public function process(MemoryRecord $record, array $context = []): MemoryRecord
    {
        $record->content = 'normalized:' . $record->content;
        $record->metadata['pipelineStage'] = 'test-stage';
        return $record;
    }
}

$repository = new TestMemoryRepository();
$store = new MemoryStore($repository);
$pipeline = new MemoryLifecyclePipeline($store, [new TestLifecycleStage()]);
$record = new MemoryRecord([
    'tenantId' => 'tenant-1',
    'userId' => 'user-42',
    'type' => 'fact',
    'content' => 'Lives in London',
    'confidence' => 0.7,
    'tags' => ['fact'],
    'metadata' => ['source' => 'test'],
]);

$result = $pipeline->process($record, ['source' => 'test']);
if ($result->content !== 'normalized:Lives in London') {
    echo 'Expected lifecycle pipeline to normalize content' . PHP_EOL;
    exit(1);
}

if (($result->metadata['pipelineStage'] ?? null) !== 'test-stage') {
    echo 'Expected lifecycle pipeline to run custom stages' . PHP_EOL;
    exit(1);
}

$pipelineStages = $result->metadata['pipelineStages'] ?? [];
if (!in_array('TestLifecycleStage', $pipelineStages, true)) {
    echo 'Expected lifecycle pipeline to include the custom stage' . PHP_EOL;
    exit(1);
}

echo 'Memory lifecycle pipeline test passed' . PHP_EOL;
