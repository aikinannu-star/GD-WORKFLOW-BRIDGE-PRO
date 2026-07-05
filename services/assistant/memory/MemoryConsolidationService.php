<?php

require_once __DIR__ . '/MemoryStore.php';
require_once __DIR__ . '/MemoryRecord.php';

class MemoryConsolidationService
{
    private MemoryStore $memoryStore;

    public function __construct(MemoryStore $memoryStore)
    {
        $this->memoryStore = $memoryStore;
    }

    public function consolidate(string $userId, string $tenantId): array
    {
        $records = $this->memoryStore->forUser($userId, $tenantId);
        $activeRecords = [];

        foreach ($records as $record) {
            if (($record->metadata['status'] ?? null) === 'archived' || ($record->metadata['status'] ?? null) === 'superseded') {
                continue;
            }

            $merged = false;
            foreach ($activeRecords as &$candidate) {
                if (!$this->isSimilar($candidate, $record)) {
                    continue;
                }

                $merged = true;
                $winner = $this->pickWinner($candidate, $record);
                $loser = $winner === $candidate ? $record : $candidate;

                $winner->confidence = max($winner->confidence, $loser->confidence);
                $winner->tags = array_unique(array_merge($winner->tags, $loser->tags));
                $winner->content = $this->composeContent($winner, $loser);
                $winner->touchUsage();
                $lineage = array_merge(
                    (array)($winner->metadata['lineage'] ?? []),
                    (array)($loser->metadata['lineage'] ?? []),
                    [[
                        'from' => $loser->content,
                        'to' => $winner->content,
                        'mergedAt' => $winner->lastConfirmedAt,
                    ]]
                );
                $dedupedLineage = [];
                foreach ($lineage as $entry) {
                    $key = json_encode($entry);
                    $dedupedLineage[$key] = $entry;
                }
                $winner->metadata['lineage'] = array_values($dedupedLineage);

                $loser->supersede($winner->id ?? 'merged', $winner->lastConfirmedAt);
                $loser->decayConfidence(0.1);
                $this->memoryStore->save($loser);
                $this->memoryStore->save($winner);
                $candidate = $winner;
                break;
            }
            unset($candidate);

            if (!$merged) {
                $activeRecords[] = $record;
            }
        }

        return $activeRecords;
    }

    private function pickWinner(MemoryRecord $a, MemoryRecord $b): MemoryRecord
    {
        if ($b->confidence > $a->confidence) {
            return $b;
        }

        if ($b->confidence < $a->confidence) {
            return $a;
        }

        if (strtotime($b->lastConfirmedAt) > strtotime($a->lastConfirmedAt)) {
            return $b;
        }

        return $a;
    }

    private function composeContent(MemoryRecord $winner, MemoryRecord $loser): string
    {
        $preferred = trim($winner->content);
        $incoming = trim($loser->content);

        if ($preferred === '') {
            return $incoming;
        }

        if ($incoming === '') {
            return $preferred;
        }

        if (strtolower($preferred) === strtolower($incoming)) {
            return $preferred;
        }

        return $incoming;
    }

    private function isSimilar(MemoryRecord $a, MemoryRecord $b): bool
    {
        if ($a->type !== $b->type) {
            return false;
        }

        $sharedTags = array_intersect($a->tags, $b->tags);
        if (count($sharedTags) > 0) {
            return true;
        }

        similar_text(strtolower($a->content), strtolower($b->content), $percent);
        return $percent >= 70;
    }
}
