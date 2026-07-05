<?php

require_once __DIR__ . '/MemoryStore.php';
require_once __DIR__ . '/MemoryRecord.php';
require_once __DIR__ . '/MemoryPolicy.php';
require_once __DIR__ . '/../ModelProviderInterface.php';

class MemoryRetrievalService
{
    private MemoryStore $memoryStore;
    private ?ModelProviderInterface $modelProvider;

    public function __construct(MemoryStore $memoryStore, ?ModelProviderInterface $modelProvider = null)
    {
        $this->memoryStore = $memoryStore;
        $this->modelProvider = $modelProvider;
    }

    public function retrieve(string $userId, string $tenantId, string $query, int $limit = 5): array
    {
        $records = $this->memoryStore->forUser($userId, $tenantId);
        $ranked = [];

        foreach ($records as $record) {
            $semanticScore = $this->semanticScore($record, $query);
            $recencyScore = $this->recencyScore($record);
            $confidenceScore = $this->confidenceScore($record);
            $importanceScore = $this->importanceScore($record);
            $frequencyScore = $this->frequencyScore($record);

            $score = (
                0.45 * $semanticScore +
                0.20 * $confidenceScore +
                0.15 * $recencyScore +
                0.10 * $importanceScore +
                0.10 * $frequencyScore
            );

            if ($score > 0.0) {
                $ranked[] = ['record' => $record, 'score' => $score];
            }
        }

        usort($ranked, function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        return array_slice(array_column($ranked, 'record'), 0, $limit);
    }

    private function semanticScore(MemoryRecord $record, string $query): float
    {
        if ($this->supportsEmbeddings()) {
            $queryEmbedding = $this->getEmbedding($query);
            $memoryEmbedding = $record->metadata['embedding'] ?? null;
            if (is_array($queryEmbedding) && is_array($memoryEmbedding)) {
                return $this->cosineSimilarity($queryEmbedding, $memoryEmbedding);
            }
        }

        $content = strtolower($record->content);
        $queryText = strtolower($query);
        $overlap = 0.0;
        foreach (preg_split('/[^a-z0-9]+/i', $queryText) ?: [] as $term) {
            if ($term === '') {
                continue;
            }
            if (strpos($content, $term) !== false) {
                $overlap += 1.0;
            }
        }

        if ($overlap === 0.0) {
            return 0.0;
        }

        return min(1.0, $overlap / max(1, substr_count($queryText, ' ') + 1));
    }

    private function getEmbedding(string $text): ?array
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

    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($a as $i => $value) {
            $dot += $value * ($b[$i] ?? 0.0);
            $normA += $value * $value;
            $normB += ($b[$i] ?? 0.0) * ($b[$i] ?? 0.0);
        }

        if ($normA <= 0 || $normB <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $dot / (sqrt($normA) * sqrt($normB))));
    }

    private function recencyScore(MemoryRecord $record): float
    {
        $lastUsed = $record->metadata['lastUsedAt'] ?? $record->lastConfirmedAt ?? null;
        if ($lastUsed === null) {
            return 0.5;
        }

        $then = strtotime($lastUsed);
        $now = time();
        if ($then === false) {
            return 0.5;
        }

        $days = max(1, ($now - $then) / 86400);
        return max(0.0, min(1.0, 1.0 - ($days / 180.0)));
    }

    private function confidenceScore(MemoryRecord $record): float
    {
        return max(0.0, min(1.0, $record->confidence));
    }

    private function importanceScore(MemoryRecord $record): float
    {
        $tags = array_map('strtolower', $record->tags);
        $score = 0.0;
        if (in_array('preference', $tags, true)) {
            $score += 0.3;
        }
        if (in_array('project', $tags, true)) {
            $score += 0.3;
        }
        if (in_array('contact', $tags, true)) {
            $score += 0.2;
        }
        if (in_array('workflow', $tags, true)) {
            $score += 0.2;
        }
        return max(0.0, min(1.0, $score));
    }

    private function frequencyScore(MemoryRecord $record): float
    {
        $lastUsed = $record->metadata['lastUsedAt'] ?? null;
        if ($lastUsed === null) {
            return 0.0;
        }

        return min(1.0, 0.2 + (count($record->sourceMessages) * 0.1));
    }
}
