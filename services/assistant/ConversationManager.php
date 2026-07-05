<?php

require_once __DIR__ . '/repositories/ConversationRepositoryInterface.php';
require_once __DIR__ . '/repositories/FileConversationRepository.php';
require_once __DIR__ . '/../dispatcher/events/RuntimeEventEmitter.php';

class ConversationManager
{
    private ConversationRepositoryInterface $repository;
    private ?RuntimeEventEmitter $eventEmitter;

    public function __construct($repository = null, string $basePath = null, ?RuntimeEventEmitter $eventEmitter = null)
    {
        if ($repository instanceof ConversationRepositoryInterface) {
            $this->repository = $repository;
        } elseif ($repository === null) {
            $this->repository = new FileConversationRepository($basePath ?: __DIR__ . '/../data/assistant/conversations');
        } else {
            throw new InvalidArgumentException('Conversation repository must implement ConversationRepositoryInterface');
        }

        $this->eventEmitter = $eventEmitter;
    }

    private function normalizeConversation(array $session): array
    {
        $id = $session['sessionId'] ?? $session['conversationId'] ?? $session['id'] ?? null;
        if (empty($id)) {
            throw new Exception('conversation_id_required');
        }

        $session['sessionId'] = $id;
        $session['conversationId'] = $session['conversationId'] ?? $id;
        $session['metadata'] = $session['metadata'] ?? [];
        $session['history'] = $session['history'] ?? [];
        $session['updatedAt'] = (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        if (empty($session['createdAt'])) {
            $session['createdAt'] = $session['updatedAt'];
        }

        return $session;
    }

    private function emit(string $event, array $payload = []): void
    {
        if ($this->eventEmitter !== null) {
            $this->eventEmitter->emit($event, $payload);
        }
    }

    public function createSession(string $sessionId, array $metadata = [], ?string $tenantId = 'default', ?string $userId = null): array
    {
        $session = $this->repository->create($sessionId, $metadata, $tenantId, $userId);
        $this->emit('conversation.created', ['conversationId' => $sessionId, 'metadata' => $session['metadata'] ?? [], 'session' => $session]);
        return $session;
    }

    public function getSession(string $sessionId): ?array
    {
        return $this->repository->get($sessionId);
    }

    public function saveSession(array $session): array
    {
        $normalized = $this->normalizeConversation($session);
        $saved = $this->repository->save($normalized);
        $this->emit('conversation.updated', ['conversationId' => $normalized['conversationId'] ?? $normalized['sessionId'], 'session' => $saved]);
        return $saved;
    }

    public function appendMessage(string $sessionId, array $message): array
    {
        $updated = $this->repository->appendMessage($sessionId, $message);
        $this->emit('conversation.updated', ['conversationId' => $sessionId, 'message' => $message, 'session' => $updated]);

        if (($message['role'] ?? null) === 'assistant') {
            $this->emit('conversation.completed', [
                'conversationId' => $sessionId,
                'sessionId' => $sessionId,
                'tenantId' => $updated['tenantId'] ?? 'default',
                'userId' => $updated['userId'] ?? null,
                'message' => $message,
                'session' => $updated,
            ]);
        }

        return $updated;
    }

    public function getHistory(string $sessionId): array
    {
        return $this->repository->getHistory($sessionId);
    }

    public function addMetadata(string $sessionId, array $metadata): array
    {
        $updated = $this->repository->addMetadata($sessionId, $metadata);
        $this->emit('conversation.metadata.updated', ['conversationId' => $sessionId, 'metadata' => $updated['metadata'] ?? [], 'session' => $updated]);
        return $updated;
    }
}
