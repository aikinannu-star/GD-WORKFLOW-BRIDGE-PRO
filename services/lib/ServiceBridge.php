<?php
/**
 * Service Bridge
 * Adapter for WordPress plugin to call service APIs
 */

class ServiceBridge
{
    private string $gatewayUrl;
    private string $userId;
    private ?string $tenantId;

    public function __construct(string $gatewayUrl = 'http://localhost:8000', string $userId = '', ?string $tenantId = null)
    {
        $this->gatewayUrl = rtrim($gatewayUrl, '/');
        $this->userId = $userId;
        $this->tenantId = $tenantId;
    }

    private function request(string $method, string $path, ?array $data = null, ?string $token = null): ?array
    {
        $url = $this->gatewayUrl . $path;
        $options = [
            'http' => [
                'method' => $method,
                'header' => [
                    'Content-Type: application/json',
                    'X-User-Id: ' . $this->userId,
                ],
                'timeout' => 10,
                'ignore_errors' => true,
            ]
        ];

        if ($token) {
            $options['http']['header'][] = 'Authorization: Bearer ' . $token;
        }
        if ($this->tenantId) {
            $options['http']['header'][] = 'X-Tenant-Id: ' . $this->tenantId;
        }
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $options['http']['content'] = json_encode($data);
        }

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        return json_decode($response, true);
    }

    // --- Authorization (gateway preflight) ---
    public function authorize(string $projectId, string $action): bool
    {
        $result = $this->request('POST', '/api/v1/cms/authorize', [
            'project_id' => $projectId,
            'action' => $action,
        ]);
        return !empty($result['allowed']);
    }

    // --- Projects ---
    public function createProject(string $title, ?string $orderId = null): ?array
    {
        $result = $this->request('POST', '/api/v1/cms/projects', [
            'tenant_id' => $this->tenantId ?? 'default',
            'title' => $title,
            'order_id' => $orderId,
            'created_by' => $this->userId,
        ]);
        return $result['project'] ?? null;
    }

    public function getProject(string $projectId): ?array
    {
        $result = $this->request('GET', '/api/v1/cms/projects/' . $projectId);
        return $result['project'] ?? null;
    }

    public function getProjects(?string $tenantId = null, ?string $userId = null): array
    {
        $path = '/api/v1/cms/projects';
        $params = [];
        if ($tenantId) {
            $params[] = 'tenant_id=' . urlencode($tenantId);
        }
        if ($userId) {
            $params[] = 'user_id=' . urlencode($userId);
        }
        if ($params) {
            $path .= '?' . implode('&', $params);
        }
        $result = $this->request('GET', $path);
        return $result['projects'] ?? [];
    }

    // --- Timeline ---
    public function getProjectTimeline(string $projectId, int $limit = 50): array
    {
        $result = $this->request('GET', '/api/v1/cms/projects/' . $projectId . '/timeline?limit=' . $limit);
        return $result['timeline'] ?? [];
    }

    public function addTimelineEntry(string $projectId, string $eventType, string $message): ?array
    {
        $result = $this->request('POST', '/api/v1/cms/projects/' . $projectId . '/timeline', [
            'event_type' => $eventType,
            'message' => $message,
        ]);
        return $result['entry'] ?? null;
    }

    // --- Chat ---
    public function getChatMessages(string $projectId): array
    {
        $result = $this->request('GET', '/api/v1/cms/chat/' . $projectId . '/messages');
        return $result['messages'] ?? [];
    }

    public function sendChatMessage(string $projectId, string $message, bool $isPrivate = false): ?array
    {
        if (!$this->authorize($projectId, 'send')) {
            return null;
        }
        $result = $this->request('POST', '/api/v1/cms/chat/' . $projectId . '/send', [
            'message' => $message,
            'is_private' => $isPrivate,
        ]);
        return $result['message'] ?? null;
    }

    // --- File Vault ---
    public function getVaultFiles(string $projectId): array
    {
        $result = $this->request('GET', '/api/v1/cms/vault/' . $projectId . '/files');
        return $result['files'] ?? [];
    }

    public function uploadVaultFile(string $projectId, string $fileName, int $fileSize, string $mimeType = 'application/octet-stream'): ?array
    {
        if (!$this->authorize($projectId, 'upload')) {
            return null;
        }
        $result = $this->request('POST', '/api/v1/cms/vault/' . $projectId . '/upload', [
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
        ]);
        if (isset($result['error'])) {
            return null;
        }
        return $result['file'] ?? null;
    }

    public function deleteVaultFile(string $fileId): bool
    {
        $result = $this->request('DELETE', '/api/v1/cms/vault/' . $fileId . '/delete');
        return $result['success'] ?? false;
    }

    // --- Forms ---
    public function getFormSubmissions(string $projectId): array
    {
        $result = $this->request('GET', '/api/v1/cms/forms/' . $projectId . '/submissions');
        return $result['submissions'] ?? [];
    }

    public function submitRevisionRequest(string $projectId, string $title, string $description, string $priority = 'medium'): bool
    {
        if (!$this->authorize($projectId, 'submit')) {
            return false;
        }
        $result = $this->request('POST', '/api/v1/cms/forms/' . $projectId . '/revision-request', [
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
        ]);
        return $result['success'] ?? false;
    }

    public function submitRequirements(string $projectId, string $requirements, ?string $deadline = null): bool
    {
        if (!$this->authorize($projectId, 'submit')) {
            return false;
        }
        $result = $this->request('POST', '/api/v1/cms/forms/' . $projectId . '/requirements', [
            'requirements' => $requirements,
            'deadline' => $deadline,
        ]);
        return $result['success'] ?? false;
    }

    // --- Notifications ---
    public function getNotifications(bool $unreadOnly = false): array
    {
        $path = '/api/v1/cms/notifications';
        if ($unreadOnly) {
            $path .= '?unread_only=true';
        }
        $result = $this->request('GET', $path);
        return $result['notifications'] ?? [];
    }

    public function markNotificationRead(string $notificationId): bool
    {
        $result = $this->request('POST', '/api/v1/cms/notifications/' . $notificationId . '/mark-read', []);
        return $result['success'] ?? false;
    }

    // --- Stats ---
    public function getStats(?string $tenantId = null, ?string $userId = null): ?array
    {
        $path = '/api/v1/cms/stats';
        $params = [];
        if ($tenantId) {
            $params[] = 'tenant_id=' . urlencode($tenantId);
        }
        if ($userId) {
            $params[] = 'user_id=' . urlencode($userId);
        }
        if ($params) {
            $path .= '?' . implode('&', $params);
        }
        $result = $this->request('GET', $path);
        return $result['stats'] ?? null;
    }
}
