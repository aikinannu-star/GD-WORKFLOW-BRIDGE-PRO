<?php

require_once __DIR__ . '/ConversationMetadata.php';
require_once __DIR__ . '/AssistantContext.php';

class SessionRestorer
{
    private AssistantRuntime $runtime;

    public function __construct(AssistantRuntime $runtime)
    {
        $this->runtime = $runtime;
    }

    /**
     * Restore a conversation session and prepare it for continued execution
     */
    public function restoreConversation(string $conversationId): ?array
    {
        $session = $this->runtime->conversationManager->getSession($conversationId);
        if (!$session) {
            return null;
        }

        $metadataData = $session['metadata'] ?? [];
        $metadataData['conversationId'] = $conversationId;
        $metadataData['userId'] = $metadataData['userId'] ?? ($session['userId'] ?? null);
        $metadataData['tenantId'] = $metadataData['tenantId'] ?? ($session['tenantId'] ?? 'default');
        $metadataData['createdAt'] = $metadataData['createdAt'] ?? ($session['createdAt'] ?? null);
        $metadataData['updatedAt'] = $metadataData['updatedAt'] ?? ($session['updatedAt'] ?? null);

        $metadata = new ConversationMetadata($metadataData);

        if ($metadata->isClosed()) {
            throw new Exception('conversation_closed_cannot_restore');
        }

        // Emit restoration event
        $this->runtime->eventEmitter->emit('conversation.restored', [
            'conversationId' => $conversationId,
            'assistantId' => $metadata->assistantId,
            'userId' => $metadata->userId,
        ]);

        return [
            'session' => $session,
            'metadata' => $metadata,
            'history' => $session['history'] ?? [],
            'context' => $this->buildRestoredContext($metadata),
        ];
    }

    /**
     * Build an AssistantContext from persisted metadata
     */
    private function buildRestoredContext(ConversationMetadata $metadata): AssistantContext
    {
        $context = new AssistantContext(
            $metadata->assistantId ?? 'default',
            $metadata->conversationId ?? 'unknown',
            $metadata->conversationId ?? null,
            $metadata->tenantId,
            $metadata->userId
        );
        
        $context->metadata = ['restored' => true, 'lastWorkflow' => $metadata->lastWorkflowId];
        return $context;
    }

    /**
     * Continue a conversation and update metadata
     */
    public function continueConversation(
        string $conversationId,
        array $message,
        ?string $assistantId = null
    ): array
    {
        $restored = $this->restoreConversation($conversationId);
        if (!$restored) {
            throw new Exception('conversation_not_found');
        }

        $session = $restored['session'];
        $metadata = $restored['metadata'];

        // Verify assistant compatibility if specified
        if ($assistantId && $metadata->assistantId && $assistantId !== $metadata->assistantId) {
            throw new Exception('assistant_mismatch');
        }

        // Append the new message
        $updated = $this->runtime->conversationManager->appendMessage($conversationId, $message);

        // Update metadata
        $metadata->messageCount++;
        $metadata->updatedAt = (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);

        $this->runtime->conversationManager->addMetadata($conversationId, $metadata->toArray());

        // Emit continuation event
        $this->runtime->eventEmitter->emit('conversation.continued', [
            'conversationId' => $conversationId,
            'messageCount' => $metadata->messageCount,
        ]);

        return [
            'session' => $updated,
            'context' => $restored['context'],
        ];
    }

    /**
     * Archive a conversation
     */
    public function archiveConversation(string $conversationId): void
    {
        $session = $this->runtime->conversationManager->getSession($conversationId);
        if (!$session) {
            throw new Exception('conversation_not_found');
        }

        $metadata = new ConversationMetadata($session['metadata'] ?? []);
        $metadata->status = 'archived';
        $metadata->updatedAt = (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);

        $this->runtime->conversationManager->addMetadata($conversationId, $metadata->toArray());

        $this->runtime->eventEmitter->emit('conversation.archived', [
            'conversationId' => $conversationId,
        ]);
    }

    /**
     * List active conversations for a user
     */
    public function listActiveConversations(string $userId, string $tenantId = 'default'): array
    {
        // This would require repository-level filtering
        // For now, return empty; enhance repository to support filtering
        return [];
    }

    /**
     * Get conversation history with optional pruning
     */
    public function getContextWindow(string $conversationId, int $maxMessages = 50): array
    {
        $history = $this->runtime->conversationManager->getHistory($conversationId);

        // Prune to recent messages if needed
        if (count($history) > $maxMessages) {
            $pruned = array_slice($history, -$maxMessages);
            // Could emit pruning event here
            return $pruned;
        }

        return $history;
    }
}
