<?php
interface WorkflowRepositoryInterface
{
    /**
     * Save a workflow record and return the stored record (including id, timestamps).
     * @param array $record
     * @return array
     */
    public function save(array $record): array;

    /**
     * Retrieve a workflow by id, or null if not found.
     * @param string $id
     * @return array|null
     */
    public function get(string $id): ?array;

    /**
     * List workflows for a tenant.
     * @param string $tenantId
     * @return array
     */
    public function listByTenant(string $tenantId): array;

    /**
     * Update a workflow record by id with given changes.
     * @param string $id
     * @param array $changes
     * @return array
     */
    public function update(string $id, array $changes): array;

    /**
     * Publish a workflow (business operation).
     * @param string $id
     * @param string $by
     * @return array
     */
    public function publish(string $id, string $by): array;

    /**
     * Archive a workflow.
     * @param string $id
     * @param string $by
     * @return array
     */
    public function archive(string $id, string $by): array;

    /**
     * Return version history for a workflow id.
     * @param string $id
     * @return array
     */
    public function versions(string $id): array;
}
