<?php

require_once __DIR__ . '/ToolInterface.php';

class ToolRegistry
{
    /** @var ToolInterface[] */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[] = $tool;
    }

    public function find(string $taskType): ?ToolInterface
    {
        foreach ($this->tools as $tool) {
            if ($tool->supports($taskType)) {
                return $tool;
            }
        }
        return null;
    }

    public function dispatch(string $taskType, array $payload): array
    {
        $tool = $this->find($taskType);
        if ($tool === null) {
            return ['status' => 400, 'result' => ['error' => 'unknown_task', 'message' => 'No tool supports ' . $taskType]];
        }
        return $tool->execute($payload);
    }
}
