<?php
interface ExecutionRepositoryInterface
{
    public function save(array $record): array;
    public function get(string $executionId): ?array;
    public function listByWorkflow(string $workflowId): array;
    public function update(string $executionId, array $changes): array;
}
