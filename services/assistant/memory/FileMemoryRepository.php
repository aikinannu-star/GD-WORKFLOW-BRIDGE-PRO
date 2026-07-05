<?php

require_once __DIR__ . '/MemoryRepositoryInterface.php';
require_once __DIR__ . '/MemoryRecord.php';

class FileMemoryRepository implements MemoryRepositoryInterface
{
    private string $basePath;
    private int $listLimit;

    public function __construct(string $basePath = null, int $listLimit = 1000)
    {
        $this->basePath = $basePath ?: __DIR__ . '/../../data/assistant/memory';
        $this->listLimit = $listLimit;
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

    public function save(MemoryRecord $record): MemoryRecord
    {
        if ($record->id === null) {
            $record->id = uniqid('memory_', true);
        }

        $data = $record->toArray();
        file_put_contents($this->path($record->id), json_encode($data, JSON_PRETTY_PRINT));
        return $record;
    }

    public function get(string $id): ?MemoryRecord
    {
        $path = $this->path($id);
        if (!file_exists($path)) {
            return null;
        }

        return new MemoryRecord(json_decode(file_get_contents($path), true));
    }

    public function listByUser(string $userId, string $tenantId = 'default'): array
    {
        $records = [];
        $files = glob($this->basePath . DIRECTORY_SEPARATOR . '*.json');

        // Sort files by modification time descending so we collect the most
        // recent records first and can stop once we reach the configured limit.
        usort($files, function (string $a, string $b): int {
            return filemtime($b) <=> filemtime($a);
        });

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (($data['userId'] ?? null) === $userId && ($data['tenantId'] ?? 'default') === $tenantId) {
                $records[] = new MemoryRecord($data);
                if (count($records) >= $this->listLimit) {
                    break;
                }
            }
        }

        usort($records, function (MemoryRecord $a, MemoryRecord $b): int {
            return strcmp($b->lastConfirmedAt, $a->lastConfirmedAt);
        });

        return $records;
    }

    public function search(string $userId, string $tenantId, array $filters = []): array
    {
        $records = $this->listByUser($userId, $tenantId);
        $filtered = [];

        foreach ($records as $record) {
            $matches = true;
            if (!empty($filters['type']) && $record->type !== $filters['type']) {
                $matches = false;
            }
            if (!empty($filters['tag']) && !in_array($filters['tag'], $record->tags, true)) {
                $matches = false;
            }
            if (!empty($filters['keyword']) && stripos($record->content, $filters['keyword']) === false) {
                $matches = false;
            }
            if ($matches) {
                $filtered[] = $record;
            }
        }

        return $filtered;
    }

    public function delete(string $id): bool
    {
        $path = $this->path($id);
        if (!file_exists($path)) {
            return false;
        }

        unlink($path);
        return true;
    }

    public function deleteExpired(string $tenantId = 'default'): int
    {
        $deleted = 0;
        $files = glob($this->basePath . DIRECTORY_SEPARATOR . '*.json');
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (($data['tenantId'] ?? 'default') !== $tenantId) {
                continue;
            }
            $record = new MemoryRecord($data);
            if ($record->isExpired()) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
