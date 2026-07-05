<?php
require_once __DIR__ . '/ExecutionRepositoryInterface.php';

class FileExecutionRepository implements ExecutionRepositoryInterface
{
    private $basePath;

    public function __construct(string $basePath = null)
    {
        $this->basePath = $basePath ?: __DIR__ . '/../../data/executions';
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
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        if (empty($record['executionId'])) { $record['executionId'] = bin2hex(random_bytes(12)); }
        if (empty($record['startedAt'])) { $record['startedAt'] = $now; }
        if (!isset($record['retryCount'])) { $record['retryCount'] = 0; }
        if (!isset($record['triggerSource'])) { $record['triggerSource'] = 'manual'; }
        $record['updatedAt'] = $now;
        $workflowId = $record['workflowId'] ?? 'default';
        $dir = $this->workflowPath($workflowId);
        $filename = $dir . DIRECTORY_SEPARATOR . $record['executionId'] . '.json';
        file_put_contents($filename, json_encode($record, JSON_PRETTY_PRINT));
        return $record;
    }

    public function get(string $executionId): ?array
    {
        $it = new RecursiveDirectoryIterator($this->basePath, RecursiveDirectoryIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($it);
        foreach ($ri as $file) {
            if ($file->isFile() && $file->getFilename() === $executionId . '.json') {
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

    public function update(string $executionId, array $changes): array
    {
        $existing = $this->get($executionId);
        if ($existing === null) { throw new Exception('not_found'); }
        $merged = array_replace_recursive($existing, $changes);
        $merged['updatedAt'] = (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        return $this->save($merged);
    }
}
