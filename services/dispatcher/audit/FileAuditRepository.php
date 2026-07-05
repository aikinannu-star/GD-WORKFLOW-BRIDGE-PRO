<?php
require_once __DIR__ . '/AuditRepositoryInterface.php';

class FileAuditRepository implements AuditRepositoryInterface
{
    private $basePath;

    public function __construct(string $basePath = null)
    {
        $this->basePath = $basePath ?: __DIR__ . '/../../data/audit';
        if (!is_dir($this->basePath)) { mkdir($this->basePath, 0775, true); }
    }

    private function tenantPath(string $tenantId): string
    {
        $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $tenantId ?: 'default');
        $p = $this->basePath . DIRECTORY_SEPARATOR . $safe;
        if (!is_dir($p)) { mkdir($p, 0775, true); }
        return $p;
    }

    public function append(array $event): array
    {
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        if (empty($event['eventId'])) { $event['eventId'] = bin2hex(random_bytes(12)); }
        $event['timestamp'] = $event['timestamp'] ?? $now;

        $tenant = $event['tenantId'] ?? 'default';
        $dir = $this->tenantPath($tenant);
        $filename = $dir . DIRECTORY_SEPARATOR . ($event['eventId']) . '.json';
        file_put_contents($filename, json_encode($event, JSON_PRETTY_PRINT));
        return $event;
    }

    public function listForWorkflow(string $workflowId): array
    {
        $res = [];
        $it = new RecursiveDirectoryIterator($this->basePath, RecursiveDirectoryIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($it);
        foreach ($ri as $file) {
            if ($file->isFile()) {
                $c = file_get_contents($file->getPathname());
                $e = json_decode($c, true);
                if ($e && isset($e['workflowId']) && $e['workflowId'] === $workflowId) {
                    $res[] = $e;
                }
            }
        }
        usort($res, function($a,$b){ return strcmp($a['timestamp'] ?? '', $b['timestamp'] ?? ''); });
        return $res;
    }
}
