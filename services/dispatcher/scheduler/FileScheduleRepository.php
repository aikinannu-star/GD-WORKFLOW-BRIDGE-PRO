<?php
require_once __DIR__ . '/ScheduleRepositoryInterface.php';

class FileScheduleRepository implements ScheduleRepositoryInterface
{
    private $basePath;

    public function __construct(string $basePath = null)
    {
        $this->basePath = $basePath ?: __DIR__ . '/../../data/schedules';
        if (!is_dir($this->basePath)) { mkdir($this->basePath, 0775, true); }
    }

    private function workflowPath(string $workflowId): string
    {
        $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $workflowId ?: 'default');
        $p = $this->basePath . DIRECTORY_SEPARATOR . $safe;
        if (!is_dir($p)) { mkdir($p, 0775, true); }
        return $p;
    }

    public function save(array $record): array
    {
        if (empty($record['id'])) { $record['id'] = bin2hex(random_bytes(12)); }
        $workflowId = $record['workflowId'] ?? 'default';
        $dir = $this->workflowPath($workflowId);
        $filename = $dir . DIRECTORY_SEPARATOR . $record['id'] . '.json';
        file_put_contents($filename, json_encode($record, JSON_PRETTY_PRINT));
        return $record;
    }

    public function get(string $id): ?array
    {
        $it = new RecursiveDirectoryIterator($this->basePath, RecursiveDirectoryIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($it);
        foreach ($ri as $file) {
            if ($file->isFile() && $file->getFilename() === $id . '.json') {
                $c = file_get_contents($file->getPathname());
                return json_decode($c, true);
            }
        }
        return null;
    }

    public function listByWorkflow(string $workflowId): array
    {
        $dir = $this->workflowPath($workflowId);
        $res = [];
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
        foreach ($files as $f) {
            $c = file_get_contents($f);
            $res[] = json_decode($c, true);
        }
        return $res;
    }

    public function delete(string $id): bool
    {
        $it = new RecursiveDirectoryIterator($this->basePath, RecursiveDirectoryIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($it);
        foreach ($ri as $file) {
            if ($file->isFile() && $file->getFilename() === $id . '.json') {
                return unlink($file->getPathname());
            }
        }
        return false;
    }
}
