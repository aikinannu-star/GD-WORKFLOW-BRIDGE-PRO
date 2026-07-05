<?php

require_once __DIR__ . '/MemoryRepositoryInterface.php';
require_once __DIR__ . '/MemoryRecord.php';
require_once __DIR__ . '/FileMemoryRepository.php';
require_once __DIR__ . '/SqlMemoryRepository.php';

class HybridMemoryRepository implements MemoryRepositoryInterface
{
    private MemoryRepositoryInterface $metadataRepository;
    private MemoryRepositoryInterface $vectorRepository;

    public function __construct(MemoryRepositoryInterface $metadataRepository = null, MemoryRepositoryInterface $vectorRepository = null)
    {
        $this->metadataRepository = $metadataRepository ?? new FileMemoryRepository();
        $this->vectorRepository = $vectorRepository ?? new FileMemoryRepository();
    }

    public function save(MemoryRecord $record): MemoryRecord
    {
        $saved = $this->metadataRepository->save($record);
        $this->vectorRepository->save($record);
        return $saved;
    }

    public function get(string $id): ?MemoryRecord
    {
        return $this->metadataRepository->get($id);
    }

    public function listByUser(string $userId, string $tenantId = 'default'): array
    {
        return $this->metadataRepository->listByUser($userId, $tenantId);
    }

    public function search(string $userId, string $tenantId, array $filters = []): array
    {
        return $this->metadataRepository->search($userId, $tenantId, $filters);
    }

    public function delete(string $id): bool
    {
        $this->vectorRepository->delete($id);
        return $this->metadataRepository->delete($id);
    }

    public function deleteExpired(string $tenantId = 'default'): int
    {
        return $this->metadataRepository->deleteExpired($tenantId);
    }
}
