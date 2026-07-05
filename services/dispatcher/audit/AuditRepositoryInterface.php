<?php
interface AuditRepositoryInterface
{
    /**
     * Append an audit event and return the stored event.
     * @param array $event
     * @return array
     */
    public function append(array $event): array;

    /**
     * List audit events for a workflow id.
     * @param string $workflowId
     * @return array
     */
    public function listForWorkflow(string $workflowId): array;
}
