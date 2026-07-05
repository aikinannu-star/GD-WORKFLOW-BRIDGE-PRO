<?php
require_once __DIR__ . '/../triggers/TriggerRegistry.php';
require_once __DIR__ . '/WorkflowEventService.php';

class WorkflowTriggerService
{
    private $registry;
    private $eventService;

    public function __construct($registry = null, $eventService = null)
    {
        $this->registry = $registry ?: new TriggerRegistry();
        $this->eventService = $eventService ?: new WorkflowEventService();
    }

    public function trigger(string $workflowId, string $type = 'manual', array $context = []): array
    {
        $trigger = $this->registry->resolve($type);
        if ($trigger === null) {
            throw new Exception('unsupported_trigger');
        }

        $result = $trigger->execute(['type' => $type], $context);
        $this->eventService->publish('workflow.triggered', ['workflowId' => $workflowId, 'trigger' => $type, 'context' => $context]);

        return [
            'workflowId' => $workflowId,
            'trigger' => $type,
            'result' => $result,
        ];
    }
}
