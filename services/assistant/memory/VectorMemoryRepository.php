<?php

require_once __DIR__ . '/MemoryRepositoryInterface.php';
require_once __DIR__ . '/MemoryRecord.php';
require_once __DIR__ . '/FileMemoryRepository.php';

class VectorMemoryRepository implements MemoryRepositoryInterface
{
    private FileMemoryRepository $backend;

    public function __construct(?string $basePath = null, int $listLimit = 1000)
    {
        $this->backend = new FileMemoryRepository($basePath ?? __DIR__ . '/../../data/assistant/vector-memory', $listLimit);
    }

    public function save(MemoryRecord $record): MemoryRecord
    {
        return $this->backend->save($record);
    }

    public function get(string $id): ?MemoryRecord
    {
        return $this->backend->get($id);
    }

    public function listByUser(string $userId, string $tenantId = 'default'): array
    {
        return $this->backend->listByUser($userId, $tenantId);
    }

    public function search(string $userId, string $tenantId, array $filters = []): array
    {
        return $this->backend->search($userId, $tenantId, $filters);
    }

    public function delete(string $id): bool
    {
        return $this->backend->delete($id);
    }

    public function deleteExpired(string $tenantId = 'default'): int
    {
        return $this->backend->deleteExpired($tenantId);
    }
}
