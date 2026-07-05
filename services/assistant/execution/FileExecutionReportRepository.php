<?php

require_once __DIR__ . '/ExecutionReportRepositoryInterface.php';

class FileExecutionReportRepository implements ExecutionReportRepositoryInterface
{
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? __DIR__ . '/../../data/execution_reports';
        if (!is_dir($this->basePath)) {
            @mkdir($this->basePath, 0777, true);
        }
    }

    public function save(array $report): bool
    {
        $id = $report['metadata']['execution_id'] ?? ($report['startedAt'] ? strval($report['startedAt']) : uniqid('exec_', true));
        $filename = $this->basePath . '/' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $id) . '_' . uniqid() . '.json';
        $payload = json_encode($report, JSON_PRETTY_PRINT);
        return (bool)file_put_contents($filename, $payload);
    }

    public function savePartial(string $executionId, array $partial): bool
    {
        $filename = $this->basePath . '/' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $executionId) . '.partial.json';
        $payload = json_encode($partial, JSON_PRETTY_PRINT);
        return (bool)file_put_contents($filename, $payload);
    }
}
