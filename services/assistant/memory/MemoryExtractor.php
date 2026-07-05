<?php

require_once __DIR__ . '/MemoryRecord.php';
require_once __DIR__ . '/MemoryRepositoryInterface.php';
require_once __DIR__ . '/../ModelProviderInterface.php';
require_once __DIR__ . '/MemoryPolicy.php';
require_once __DIR__ . '/MemoryStore.php';
require_once __DIR__ . '/MemoryLifecyclePipeline.php';

class MemoryExtractor
{
    private MemoryRepositoryInterface $memoryRepository;
    private ?MemoryStore $memoryStore;
    private MemoryPolicy $policy;
    private ?ModelProviderInterface $modelProvider;
    private ?MemoryLifecyclePipeline $lifecyclePipeline;

    public function __construct(MemoryRepositoryInterface $memoryRepository, ?MemoryPolicy $policy = null, ?MemoryStore $memoryStore = null, ?ModelProviderInterface $modelProvider = null, ?MemoryLifecyclePipeline $lifecyclePipeline = null)
    {
        $this->memoryRepository = $memoryRepository;
        $this->memoryStore = $memoryStore;
        $this->policy = $policy ?? new MemoryPolicy();
        $this->modelProvider = $modelProvider;
        $this->lifecyclePipeline = $lifecyclePipeline ?? ($memoryStore !== null ? new MemoryLifecyclePipeline($memoryStore) : null);
    }

    public function extractFromConversation(array $conversation, array $metadata = [], ?MemoryPolicy $policy = null): array
    {
        $policy = $policy ?? $this->policy;
        $records = [];
        $messages = $conversation['history'] ?? [];

        foreach ($messages as $message) {
            $content = $message['content'] ?? $message['text'] ?? '';
            if (!is_string($content) || trim($content) === '') {
                continue;
            }

            $classification = $this->classifyMessage($content, $policy);
            if ($classification === null) {
                continue;
            }

            $record = $this->createRecord($conversation, $message, $classification['type'], $content, $classification['tags'], $metadata, $classification['confidence']);
            $records[] = $this->persistRecord($record);
        }

        return $records;
    }

    private function classifyMessage(string $content, MemoryPolicy $policy): ?array
    {
        $text = strtolower($content);

        if ($policy->rememberTemporaryInformation === false && preg_match('/\b(today|tomorrow|for now|temporary|just for now)\b/i', $content)) {
            return null;
        }

        if (preg_match('/\b(prefer|prefer|like|favorite|love)\b/i', $text)) {
            return $policy->rememberUserPreferences ? ['type' => 'preference', 'tags' => ['preference'], 'confidence' => 0.8] : null;
        }

        if (preg_match('/\b(goal|want|need|plan|target|aim)\b/i', $text)) {
            return $policy->rememberFacts ? ['type' => 'goal', 'tags' => ['goal'], 'confidence' => 0.75] : null;
        }

        if (preg_match('/\b(project|initiative|milestone|roadmap)\b/i', $text)) {
            return $policy->rememberProjects ? ['type' => 'project', 'tags' => ['project'], 'confidence' => 0.82] : null;
        }

        if (preg_match('/\b(contact|email|phone|reach out|address)\b/i', $text)) {
            return $policy->rememberContacts ? ['type' => 'contact', 'tags' => ['contact'], 'confidence' => 0.78] : null;
        }

        if (preg_match('/\b(workflow|process|procedure|policy)\b/i', $text)) {
            return $policy->rememberFacts ? ['type' => 'workflow', 'tags' => ['workflow'], 'confidence' => 0.72] : null;
        }

        return $policy->rememberFacts ? ['type' => 'fact', 'tags' => ['fact'], 'confidence' => 0.65] : null;
    }

    private function persistRecord(MemoryRecord $record): MemoryRecord
    {
        if ($this->lifecyclePipeline !== null) {
            return $this->lifecyclePipeline->process($record, ['source' => 'conversation']);
        }

        if ($this->memoryStore !== null) {
            return $this->memoryStore->add($record);
        }

        return $this->memoryRepository->save($record);
    }

    private function createRecord(array $conversation, array $message, string $type, string $content, array $tags, array $metadata = [], float $confidence = 0.65): MemoryRecord
    {
        $metadata = array_merge([
            'source' => 'conversation',
            'sourceEvent' => $metadata['event'] ?? 'conversation.completed',
            'sourceOrigin' => $metadata['source'] ?? 'runtime',
        ], $conversation['metadata'] ?? [], $metadata);

        if ($this->modelProvider !== null) {
            $embedding = $this->computeEmbedding($content);
            if (is_array($embedding)) {
                $metadata['embedding'] = $embedding;
            }
        }

        $record = new MemoryRecord([
            'tenantId' => $conversation['tenantId'] ?? 'default',
            'userId' => $conversation['userId'] ?? 'unknown',
            'conversationId' => $conversation['conversationId'] ?? $conversation['sessionId'] ?? null,
            'type' => $type,
            'content' => $content,
            'confidence' => $confidence,
            'tags' => $tags,
            'sourceMessages' => [($message['timestamp'] ?? null), $message['role'] ?? 'unknown'],
            'metadata' => $metadata,
        ]);

        return $record;
    }

    private function computeEmbedding(string $text): ?array
    {
        if ($this->modelProvider === null || !$this->supportsEmbeddings()) {
            return null;
        }

        $result = $this->modelProvider->embeddings($text, []);
        if (!is_array($result)) {
            return null;
        }

        if (isset($result['vector']) && is_array($result['vector'])) {
            return $result['vector'];
        }

        if (isset($result['data']) && is_array($result['data'])) {
            return $result['data'];
        }

        return null;
    }

    private function supportsEmbeddings(): bool
    {
        if ($this->modelProvider === null) {
            return false;
        }

        $capabilities = $this->modelProvider->capabilities();
        if (isset($capabilities['embeddings'])) {
            return (bool)$capabilities['embeddings'];
        }

        return in_array('embeddings', $capabilities, true);
    }
}
