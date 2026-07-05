<?php
require_once __DIR__ . '/WorkflowRepositoryInterface.php';

class FileWorkflowRepository implements WorkflowRepositoryInterface
{
    private $basePath;

    public function __construct(string $basePath = null)
    {
        $this->basePath = $basePath ?: __DIR__ . '/../../data/workflows';
        if (!is_dir($this->basePath)) { mkdir($this->basePath, 0775, true); }
    }

    private function tenantPath(string $tenantId): string
    {
        $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $tenantId ?: 'default');
        $p = $this->basePath . DIRECTORY_SEPARATOR . $safe;
        if (!is_dir($p)) { mkdir($p, 0775, true); }
        return $p;
    }

    public function save(array $record): array
    {
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        if (empty($record['id'])) { $record['id'] = bin2hex(random_bytes(12)); }
        if (empty($record['createdAt'])) { $record['createdAt'] = $now; }
        $record['updatedAt'] = $now;
        if (empty($record['version'])) { $record['version'] = 1; }

        $tenant = $record['tenantId'] ?? 'default';
        $dir = $this->tenantPath($tenant);
        $name = preg_replace('/[^a-z0-9_\-]/i', '_', $record['name'] ?? $record['id']);
        // Version-aware filename: v1 => name-id.json, vN => name-id-vN.json
        $version = intval($record['version']);
        if ($version <= 1) {
            $filename = $dir . DIRECTORY_SEPARATOR . $name . '-' . $record['id'] . '.json';
        } else {
            $filename = $dir . DIRECTORY_SEPARATOR . $name . '-' . $record['id'] . '-v' . $version . '.json';
        }
        file_put_contents($filename, json_encode($record, JSON_PRETTY_PRINT));
        return $record;
    }

    public function get(string $id): ?array
    {
        $it = new RecursiveDirectoryIterator($this->basePath, RecursiveDirectoryIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($it);
        foreach ($ri as $file) {
            if ($file->isFile() && stripos($file->getFilename(), $id) !== false) {
                $c = file_get_contents($file->getPathname());
                return json_decode($c, true);
            }
        }
        return null;
    }

    public function update(string $id, array $changes): array
    {
        $existing = $this->get($id);
        if ($existing === null) { throw new Exception('not_found'); }
        $merged = array_replace_recursive($existing, $changes);
        // preserve id
        $merged['id'] = $id;
        // keep version the same until publish
        $merged['version'] = $existing['version'] ?? 1;
        return $this->save($merged);
    }

    public function publish(string $id, string $by): array
    {
        $existing = $this->get($id);
        if ($existing === null) { throw new Exception('not_found'); }
        $version = intval($existing['version'] ?? 1) + 1;
        $existing['version'] = $version;
        $existing['status'] = 'published';
        $existing['publishedAt'] = (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        $existing['publishedBy'] = $by;
        $existing['locked'] = true;
        return $this->save($existing);
    }

    public function archive(string $id, string $by): array
    {
        $existing = $this->get($id);
        if ($existing === null) { throw new Exception('not_found'); }
        $existing['status'] = 'archived';
        $existing['archivedAt'] = (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        $existing['archivedBy'] = $by;
        return $this->save($existing);
    }

    public function versions(string $id): array
    {
        $res = [];
        $it = new RecursiveDirectoryIterator($this->basePath, RecursiveDirectoryIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($it);
        foreach ($ri as $file) {
            if ($file->isFile() && stripos($file->getFilename(), $id) !== false) {
                $c = file_get_contents($file->getPathname());
                $res[] = json_decode($c, true);
            }
        }
        usort($res, function($a, $b){ return ($a['version'] ?? 0) - ($b['version'] ?? 0); });
        return $res;
    }

    public function listByTenant(string $tenantId): array
    {
        $dir = $this->tenantPath($tenantId);
        $res = [];
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
        foreach ($files as $f) {
            $c = file_get_contents($f);
            $res[] = json_decode($c, true);
        }
        return $res;
    }
}
