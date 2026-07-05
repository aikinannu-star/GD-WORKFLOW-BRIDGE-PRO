<?php

interface MemoryRepositoryInterface
{
    public function save(MemoryRecord $record): MemoryRecord;
    public function get(string $id): ?MemoryRecord;
    public function listByUser(string $userId, string $tenantId = 'default'): array;
    public function search(string $userId, string $tenantId, array $filters = []): array;
    public function delete(string $id): bool;
    public function deleteExpired(string $tenantId = 'default'): int;
}
