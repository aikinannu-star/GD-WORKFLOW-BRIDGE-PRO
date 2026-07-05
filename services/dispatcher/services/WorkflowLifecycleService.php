<?php
require_once __DIR__ . '/../repositories/FileWorkflowRepository.php';
require_once __DIR__ . '/../validators/WorkflowValidator.php';
require_once __DIR__ . '/AuditService.php';

class WorkflowLifecycleService
{
    private $repo;
    private $validator;
    private $audit;

    public function __construct($repo = null, $validator = null, $audit = null)
    {
        $this->repo = $repo ?: new FileWorkflowRepository();
        $this->validator = $validator ?: new WorkflowValidator();
        $this->audit = $audit ?: new AuditService();
    }

    public function update(string $id, array $changes, string $actor = 'system'): array
    {
        $existing = $this->repo->get($id);
        if ($existing === null) { throw new Exception('not_found'); }
        // Only allow updates on non-published, non-archived workflows
        $status = $existing['status'] ?? 'draft';
        if (in_array($status, ['published', 'archived'])) {
            throw new Exception('cannot_modify_published_or_archived');
        }

        $merged = array_replace_recursive($existing, $changes);
        $merged['updatedBy'] = $actor;
        $saved = $this->repo->update($id, $merged);

        // Audit the update
        try {
            $this->audit->record($id, $saved['tenantId'] ?? 'default', $saved['version'] ?? 1, 'update', $actor, 'success', ['changes' => $changes]);
        } catch (Exception $e) {
            // swallow audit errors but keep operation successful
        }

        return $saved;
    }

    public function publish(string $id, string $actor = 'system'): array
    {
        $existing = $this->repo->get($id);
        if ($existing === null) { throw new Exception('not_found'); }
        // Validate workflow before publishing
        $workflow = $existing['workflow'] ?? [];
        $validation = $this->validator->validate(is_array($workflow) ? $workflow : []);
        if (!$validation['valid']) {
            throw new Exception(json_encode(['validation_errors' => $validation['errors']]));
        }
        $published = $this->repo->publish($id, $actor);

        // Audit the publish
        try {
            $this->audit->record($id, $published['tenantId'] ?? 'default', $published['version'] ?? 1, 'publish', $actor, 'success', []);
        } catch (Exception $e) {
            // ignore audit errors
        }

        return $published;
    }

    public function archive(string $id, string $actor = 'system'): array
    {
        $existing = $this->repo->get($id);
        if ($existing === null) { throw new Exception('not_found'); }
        $archived = $this->repo->archive($id, $actor);

        try {
            $this->audit->record($id, $archived['tenantId'] ?? 'default', $archived['version'] ?? 1, 'archive', $actor, 'success', []);
        } catch (Exception $e) {
            // ignore
        }

        return $archived;
    }

    public function versions(string $id): array
    {
        return $this->repo->versions($id);
    }
}
