<?php
require_once __DIR__ . '/../audit/FileAuditRepository.php';
require_once __DIR__ . '/../audit/AuditRepositoryInterface.php';

class AuditService
{
    private $repo;

    public function __construct($repo = null)
    {
        $this->repo = $repo ?: new FileAuditRepository();
    }

    public function recordEvent(array $data): array
    {
        // Expect keys: workflowId, tenantId, version, action, performedBy, status, details
        $event = $data;
        if (empty($event['eventId'])) { $event['eventId'] = bin2hex(random_bytes(12)); }
        if (empty($event['timestamp'])) { $event['timestamp'] = (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM); }
        return $this->repo->append($event);
    }

    public function record(string $workflowId, string $tenantId, $version, string $action, string $performedBy, string $status = 'success', array $details = []): array
    {
        $event = [
            'workflowId' => $workflowId,
            'tenantId' => $tenantId,
            'version' => $version,
            'action' => $action,
            'performedBy' => $performedBy,
            'status' => $status,
            'details' => $details,
        ];
        return $this->recordEvent($event);
    }

    public function listForWorkflow(string $workflowId): array
    {
        return $this->repo->listForWorkflow($workflowId);
    }
}
