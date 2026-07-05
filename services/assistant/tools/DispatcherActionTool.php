<?php

require_once __DIR__ . '/../ToolInterface.php';
require_once __DIR__ . '/../../dispatcher/actions/ActionRegistry.php';
require_once __DIR__ . '/../../dispatcher/runtime/ExecutionContext.php';

class DispatcherActionTool implements ToolInterface
{
    private ActionRegistry $actionRegistry;
    private string $defaultTenantId;

    public function __construct(ActionRegistry $actionRegistry, string $defaultTenantId = 'default')
    {
        $this->actionRegistry = $actionRegistry;
        $this->defaultTenantId = $defaultTenantId;
    }

    public function id(): string
    {
        return 'dispatcher_action';
    }

    public function name(): string
    {
        return 'Dispatcher Action Tool';
    }

    public function description(): string
    {
        return 'Executes dispatcher actions through the platform ActionRegistry.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string'],
                'payload' => ['type' => 'object'],
                'tenantId' => ['type' => 'string'],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $args): array
    {
        $action = $args['action'] ?? '';
        $payload = $args['payload'] ?? [];
        $tenantId = $args['tenantId'] ?? $this->defaultTenantId;

        if (!$action) {
            return ['success' => false, 'result' => null, 'error' => 'missing_action'];
        }

        if (!$this->actionRegistry->hasAction($action)) {
            return ['success' => false, 'result' => null, 'error' => 'unknown_action'];
        }

        $executionContext = new ExecutionContext(
            'assistant',
            uniqid('assistant_exec_', true),
            $tenantId,
            $payload['variables'] ?? [],
            null,
            null,
            ['source' => 'assistant_tool']
        );

        $result = $this->actionRegistry->execute($action, $payload, $executionContext);
        return [
            'success' => $result->isSuccess(),
            'result' => $result->getOutput(),
            'error' => $result->getError(),
            'duration' => $result->getDuration(),
            'logs' => $result->getLogs(),
            'warnings' => $result->getWarnings(),
        ];
    }
}
