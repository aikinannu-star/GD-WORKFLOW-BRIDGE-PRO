<?php

require_once __DIR__ . '/../ToolInterface.php';
require_once __DIR__ . '/../../dispatcher/services/WorkflowExecutionService.php';

class WorkflowTool implements ToolInterface
{
    private WorkflowExecutionService $executionService;

    public function __construct(WorkflowExecutionService $executionService)
    {
        $this->executionService = $executionService;
    }

    public function id(): string
    {
        return 'workflow_execute';
    }

    public function name(): string
    {
        return 'Workflow Execution Tool';
    }

    public function description(): string
    {
        return 'Executes a workflow through the dispatcher runtime.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'workflowId' => ['type' => 'string'],
                'input' => ['type' => 'object'],
            ],
            'required' => ['workflowId'],
        ];
    }

    public function execute(array $args): array
    {
        $workflowId = $args['workflowId'] ?? '';
        $input = $args['input'] ?? [];

        if (empty($workflowId)) {
            return ['success' => false, 'result' => null, 'error' => 'missing_workflow_id'];
        }

        try {
            $result = $this->executionService->executeById($workflowId, $input);
            return ['success' => true, 'result' => $result, 'error' => null];
        } catch (Exception $e) {
            return ['success' => false, 'result' => null, 'error' => $e->getMessage()];
        }
    }
}
