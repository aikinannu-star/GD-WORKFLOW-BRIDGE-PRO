<?php

require_once __DIR__ . '/MemoryRepositoryInterface.php';
require_once __DIR__ . '/MemoryRecord.php';

class FileMemoryRepository implements MemoryRepositoryInterface
{
    private string $basePath;
    private int $listLimit;
    private int $maxFileSizeBytes;

    public function __construct(string $basePath = null, int $listLimit = 1000, int $maxFileSizeBytes = 256 * 1024)
    {
        $this->basePath = $basePath ?: __DIR__ . '/../../data/assistant/memory';
        $this->listLimit = $listLimit;
        $this->maxFileSizeBytes = $maxFileSizeBytes;
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
        $files = $this->listCandidateFiles();

        foreach ($files as $file) {
            if (!is_readable($file)) {
                continue;
            }

            $size = filesize($file);
            if ($size === false || $size > $this->maxFileSizeBytes) {
                continue;
            }

            $data = @json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }

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

    private function listCandidateFiles(): array
    {
        if (!is_dir($this->basePath)) {
            return [];
        }

        $entries = scandir($this->basePath);
        if ($entries === false) {
            return [];
        }

        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->basePath . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path) || substr($entry, -5) !== '.json') {
                continue;
            }

            $files[] = [
                'path' => $path,
                'mtime' => filemtime($path) ?: 0,
            ];
        }

        usort($files, function (array $a, array $b): int {
            return $b['mtime'] <=> $a['mtime'];
        });

        $scanBudget = max(50, min($this->listLimit * 10, 2000));
        $candidatePaths = [];
        foreach (array_slice($files, 0, $scanBudget) as $file) {
            $candidatePaths[] = $file['path'];
        }

        return $candidatePaths;
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
