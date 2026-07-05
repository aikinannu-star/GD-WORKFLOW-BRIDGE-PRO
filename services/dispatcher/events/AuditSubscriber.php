<?php
require_once __DIR__ . '/SubscriberInterface.php';
require_once __DIR__ . '/../services/AuditService.php';

class AuditSubscriber implements SubscriberInterface
{
    private $auditService;

    public function __construct($auditService = null)
    {
        $this->auditService = $auditService ?: new AuditService();
    }

    public function supports(string $eventType): bool
    {
        return in_array($eventType, ['workflow.started','workflow.completed','workflow.failed','workflow.node.completed'], true);
    }

    public function handle(array $payload): array
    {
        $eventType = $payload['eventType'] ?? $payload['type'] ?? 'unknown';
        if (!$this->supports($eventType)) {
            return ['status' => 'ignored'];
        }

        $workflowId = $payload['workflowId'] ?? null;
        $tenantId = $payload['tenantId'] ?? 'default';
        $executionId = $payload['executionId'] ?? null;
        $action = str_replace('workflow.', '', $eventType);
        $status = $payload['status'] ?? 'success';

        $this->auditService->record($workflowId, $tenantId, $payload['version'] ?? 1, $action, $payload['actor'] ?? 'event-bus', $status, ['executionId' => $executionId, 'details' => $payload]);
        return ['status' => 'recorded', 'eventType' => $eventType, 'workflowId' => $workflowId];
    }
}
