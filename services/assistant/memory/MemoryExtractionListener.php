<?php

require_once __DIR__ . '/MemoryExtractor.php';
require_once __DIR__ . '/MemoryPolicy.php';

class MemoryExtractionListener
{
    private MemoryExtractor $extractor;
    private MemoryPolicy $policy;

    public function __construct(MemoryExtractor $extractor, ?MemoryPolicy $policy = null)
    {
        $this->extractor = $extractor;
        $this->policy = $policy ?? new MemoryPolicy();
    }

    public function __invoke(array $payload): void
    {
        $session = $payload['session'] ?? $payload;
        if (!is_array($session)) {
            return;
        }

        $conversation = [
            'conversationId' => $payload['conversationId'] ?? $session['conversationId'] ?? $session['sessionId'] ?? null,
            'sessionId' => $payload['sessionId'] ?? $session['sessionId'] ?? null,
            'tenantId' => $payload['tenantId'] ?? $session['tenantId'] ?? 'default',
            'userId' => $payload['userId'] ?? $session['userId'] ?? null,
            'history' => $session['history'] ?? [],
            'metadata' => $session['metadata'] ?? [],
        ];

        if (empty($conversation['history'])) {
            return;
        }

        $this->extractor->extractFromConversation($conversation, [
            'event' => 'conversation.completed',
            'source' => 'runtime',
        ], $this->policy);
    }
}
