<?php
require_once __DIR__ . '/../repositories/FileExecutionRepository.php';

class ExecutionStateService
{
    private $repo;

    public function __construct($repo = null)
    {
        $this->repo = $repo ?: new FileExecutionRepository();
    }

    public function start(array $record): array
    {
        return $this->repo->save($record);
    }

    public function update(string $executionId, array $changes): array
    {
        return $this->repo->update($executionId, $changes);
    }

    public function get(string $executionId): ?array
    {
        return $this->repo->get($executionId);
    }

    public function listByWorkflow(string $workflowId): array
    {
        return $this->repo->listByWorkflow($workflowId);
    }
}
