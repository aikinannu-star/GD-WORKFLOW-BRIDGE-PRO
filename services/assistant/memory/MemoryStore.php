<?php

require_once __DIR__ . '/MemoryRepositoryInterface.php';
require_once __DIR__ . '/MemoryRecord.php';
require_once __DIR__ . '/../../dispatcher/events/RuntimeEventEmitter.php';

class MemoryStore
{
    private MemoryRepositoryInterface $repository;
    private ?RuntimeEventEmitter $eventEmitter;

    public function __construct(MemoryRepositoryInterface $repository, ?RuntimeEventEmitter $eventEmitter = null)
    {
        $this->repository = $repository;
        $this->eventEmitter = $eventEmitter;
    }

    public function add(MemoryRecord $record): MemoryRecord
    {
        $candidate = $this->findBestMatch($record);
        if ($candidate !== null) {
            $winner = $this->pickWinner($candidate, $record);
            $loser = $winner === $candidate ? $record : $candidate;
            $winner->touchUsage();
            $winner->confidence = max($winner->confidence, $loser->confidence);
            $winner->tags = array_unique(array_merge($winner->tags, $loser->tags));
            $winner->content = $this->composeMergedContent($winner, $loser);
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
            $winner->metadata = array_merge($winner->metadata, $loser->metadata, [
                'lineage' => array_values($dedupedLineage),
            ]);
            $loser->supersede($winner->id ?? 'merged', $winner->lastConfirmedAt);
            $loser->decayConfidence(0.1);
            $this->repository->save($winner);
            $this->repository->save($loser);
            $this->emit('memory.updated', ['memory' => $winner]);
            return $winner;
        }

        $record->touchUsage();
        $saved = $this->repository->save($record);
        $this->emit('memory.created', ['memory' => $saved]);
        return $saved;
    }

    public function get(string $id): ?MemoryRecord
    {
        return $this->repository->get($id);
    }

    public function forUser(string $userId, string $tenantId = 'default'): array
    {
        return $this->repository->listByUser($userId, $tenantId);
    }

    public function search(string $userId, string $tenantId, array $filters = []): array
    {
        return $this->repository->search($userId, $tenantId, $filters);
    }

    public function retrieve(string $userId, string $tenantId, string $query, int $limit = 5): array
    {
        $records = $this->repository->listByUser($userId, $tenantId);
        $scored = [];

        $queryTerms = preg_split('/[^a-z0-9]+/i', strtolower($query)) ?: [];
        $queryTerms = array_values(array_filter($queryTerms));

        foreach ($records as $record) {
            $content = strtolower($record->content . ' ' . implode(' ', $record->tags));
            $score = 0.0;

            foreach ($queryTerms as $term) {
                if ($term === '') {
                    continue;
                }
                if (strpos($content, $term) !== false) {
                    $score += 1.0;
                }
            }

            if ($record->confidence > 0) {
                $score += $record->confidence;
            }

            if ($score > 0) {
                $scored[] = ['record' => $record, 'score' => $score];
            }
        }

        usort($scored, function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        $result = array_slice(array_column($scored, 'record'), 0, $limit);
        foreach ($result as $record) {
            $record->touchUsage();
            $this->repository->save($record);
        }

        $this->emit('memory.retrieved', ['query' => $query, 'records' => $result]);
        return $result;
    }

    public function archive(string $id): ?MemoryRecord
    {
        $record = $this->get($id);
        if ($record === null) {
            return null;
        }

        $record->archive();
        $saved = $this->repository->save($record);
        $this->emit('memory.archived', ['memory' => $saved]);
        return $saved;
    }

    public function supersede(string $id, string $supersededById): ?MemoryRecord
    {
        $record = $this->get($id);
        if ($record === null) {
            return null;
        }

        $record->supersede($supersededById);
        $saved = $this->repository->save($record);
        $this->emit('memory.superseded', ['memory' => $saved, 'supersededById' => $supersededById]);
        return $saved;
    }

    public function merge(string $id, string $targetId): ?MemoryRecord
    {
        $record = $this->get($id);
        $target = $this->get($targetId);
        if ($record === null || $target === null) {
            return null;
        }

        $target->tags = array_unique(array_merge($target->tags, $record->tags));
        $target->confidence = max($target->confidence, $record->confidence);
        $target->content = trim($target->content . ' ' . $record->content);
        $target->touchUsage();
        $saved = $this->repository->save($target);
        $this->repository->delete($id);
        $this->emit('memory.merged', ['memory' => $saved, 'mergedMemoryId' => $id]);
        return $saved;
    }

    public function decayConfidence(string $id, float $factor = 0.1): ?MemoryRecord
    {
        $record = $this->get($id);
        if ($record === null) {
            return null;
        }

        $record->decayConfidence($factor);
        $saved = $this->repository->save($record);
        $this->emit('memory.decayed', ['memory' => $saved]);
        return $saved;
    }

    public function delete(string $id): bool
    {
        $deleted = $this->repository->delete($id);
        if ($deleted) {
            $this->emit('memory.deleted', ['memoryId' => $id]);
        }
        return $deleted;
    }

    private function findBestMatch(MemoryRecord $record): ?MemoryRecord
    {
        if ($record->userId === '' || $record->tenantId === '') {
            return null;
        }

        $candidates = $this->repository->search($record->userId, $record->tenantId, ['type' => $record->type]);
        foreach ($candidates as $candidate) {
            if ($candidate->id === null || $candidate->id === $record->id) {
                continue;
            }
            if ($this->isSimilar($candidate, $record)) {
                return $candidate;
            }
        }

        return null;
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

        $aContent = strtolower($a->content);
        $bContent = strtolower($b->content);
        if ($aContent === '' || $bContent === '') {
            return false;
        }

        similar_text($aContent, $bContent, $percent);
        return $percent >= 60;
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

    private function composeMergedContent(MemoryRecord $winner, MemoryRecord $loser): string
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

    private function emit(string $event, array $payload = []): void
    {
        if ($this->eventEmitter !== null) {
            $this->eventEmitter->emit($event, $payload);
        }
    }
}
