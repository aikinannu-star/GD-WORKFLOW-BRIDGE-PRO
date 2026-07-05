<?php

require_once __DIR__ . '/ConversationRepositoryInterface.php';

class MemoryConversationRepository implements ConversationRepositoryInterface
{
    private array $conversations = [];

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

        $this->conversations[$id] = $conversation;
        return $conversation;
    }

    public function get(string $id): ?array
    {
        return $this->conversations[$id] ?? null;
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

        $this->conversations[$id] = $conversation;
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
