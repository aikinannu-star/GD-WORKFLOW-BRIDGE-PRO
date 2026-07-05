<?php
interface ScheduleRepositoryInterface
{
    public function save(array $record): array;
    public function get(string $id): ?array;
    public function listByWorkflow(string $workflowId): array;
    public function delete(string $id): bool;
}
