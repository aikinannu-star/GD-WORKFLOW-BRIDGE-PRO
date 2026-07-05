<?php
require_once __DIR__ . '/../ToolInterface.php';

class WorkflowTool implements ToolInterface
{
    public function supports(string $taskType): bool
    {
        return $taskType === 'generate_workflow';
    }

    public function execute(array $payload): array
    {
        $target = getenv('DISPATCHER_WORKFLOW_URL') ?: 'http://assistant-service:8017/api/v1/assistant/generate/workflow';
        $post = ['instructions' => $payload['instructions'] ?? '', 'context' => $payload['context'] ?? ''];
        $ch = curl_init($target);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($post),
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) { $err = curl_error($ch); curl_close($ch); return ['status' => 502, 'result' => ['error' => 'upstream_error', 'detail' => $err]]; }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $body = json_decode($resp, true) ?? ['raw' => $resp];

        // Extract workflow payload if assistant returned structured envelope
        $workflow = $body['payload'] ?? $body['result'] ?? $body;

        // Validate workflow structure and business rules
        require_once __DIR__ . '/../validators/WorkflowValidator.php';
        $validator = new WorkflowValidator();
        $validation = $validator->validate(is_array($workflow) ? $workflow : []);
        if (!$validation['valid']) {
            return ['status' => 422, 'result' => ['errors' => $validation['errors'], 'raw' => $workflow]];
        }

        // Persist validated workflow as draft via repository abstraction
        require_once __DIR__ . '/../repositories/FileWorkflowRepository.php';
        require_once __DIR__ . '/../repositories/WorkflowRepositoryInterface.php';
        $repo = new FileWorkflowRepository();

        $record = [
            'tenantId' => $payload['tenantId'] ?? 'default',
            'name' => $payload['name'] ?? ($workflow['name'] ?? 'generated_workflow'),
            'status' => 'draft',
            'createdBy' => $payload['createdBy'] ?? 'assistant',
            'workflow' => $workflow,
        ];

        $stored = $repo->save($record);

        // Record audit event for creation
        try {
            require_once __DIR__ . '/../services/AuditService.php';
            $audit = new AuditService();
            $audit->record($stored['id'], $stored['tenantId'] ?? 'default', $stored['version'] ?? 1, 'create', $stored['createdBy'] ?? 'assistant', 'success', []);
        } catch (Exception $e) {
            // ignore audit failures
        }

        return ['status' => ($code >=200 && $code<300) ? 200 : $code, 'result' => ['workflow' => $workflow, 'stored' => $stored]];
    }
}
