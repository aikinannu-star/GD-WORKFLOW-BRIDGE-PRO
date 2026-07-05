<?php

require_once __DIR__ . '/ConversationRepositoryInterface.php';

class FileConversationRepository implements ConversationRepositoryInterface
{
    private string $basePath;

    public function __construct(string $basePath = null)
    {
        $this->basePath = $basePath ?: __DIR__ . '/../../data/assistant/conversations';
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0775, true);
        }
    }

    private function sanitize(string $id): string
    {
        return preg_replace('/[^a-z0-9_\-]/i', '_', $id);
    }

    private function path(string $id): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . $this->sanitize($id) . '.json';
    }

    public function create(string $id, array $metadata = [], ?string $tenantId = 'default', ?string $userId = null): array
    {
        $conversation = [
            'sessionId' => $id,
            'conversationId' => $id,
            'tenantId' => $tenantId,
            'userId' => $userId,
            'metadata' => $metadata,
            'history' => [],
            'createdAt' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
            'updatedAt' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
        ];

        return $this->save($conversation);
    }

    public function get(string $id): ?array
    {
        $path = $this->path($id);
        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        return json_decode($content, true);
    }

    public function save(array $conversation): array
    {
        $id = $conversation['sessionId'] ?? $conversation['conversationId'] ?? $conversation['id'] ?? null;
        if (empty($id)) {
            throw new Exception('conversation_id_required');
        }

        $conversation['sessionId'] = $id;
        $conversation['conversationId'] = $conversation['conversationId'] ?? $id;
        $conversation['metadata'] = $conversation['metadata'] ?? [];
        $conversation['history'] = $conversation['history'] ?? [];
        $conversation['updatedAt'] = (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        if (empty($conversation['createdAt'])) {
            $conversation['createdAt'] = $conversation['updatedAt'];
        }

        $path = $this->path($id);
        file_put_contents($path, json_encode($conversation, JSON_PRETTY_PRINT));
        return $conversation;
    }

    public function appendMessage(string $id, array $message): array
    {
        $conversation = $this->get($id);
        if ($conversation === null) {
            $conversation = $this->create($id);
        }

        $message['timestamp'] = $message['timestamp'] ?? (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        $conversation['history'][] = $message;
        $conversation['metadata'] = $conversation['metadata'] ?? [];
        $conversation['metadata']['messageCount'] = ($conversation['metadata']['messageCount'] ?? 0) + 1;
        return $this->save($conversation);
    }

    public function getHistory(string $id): array
    {
        $conversation = $this->get($id);
        return $conversation['history'] ?? [];
    }

    public function addMetadata(string $id, array $metadata): array
    {
        $conversation = $this->get($id);
        if ($conversation === null) {
            $conversation = $this->create($id);
        }

        $conversation['metadata'] = array_merge($conversation['metadata'] ?? [], $metadata);
        return $this->save($conversation);
    }
}
