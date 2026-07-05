<?php

require_once __DIR__ . '/ExecutionReportRepositoryInterface.php';

class FallbackExecutionReportRepository implements ExecutionReportRepositoryInterface
{
    private ExecutionReportRepositoryInterface $primary;
    private ExecutionReportRepositoryInterface $fallback;

    public function __construct(ExecutionReportRepositoryInterface $primary, ExecutionReportRepositoryInterface $fallback)
    {
        $this->primary = $primary;
        $this->fallback = $fallback;
    }

    public function save(array $report): bool
    {
        if ($this->primary->save($report)) {
            return true;
        }

        if ($this->fallback->save($report)) {
            error_log('Execution report fallback repository used for execution ' . ($report['executionId'] ?? $report['metadata']['execution_id'] ?? 'unknown'));
            return true;
        }

        return false;
    }

    public function savePartial(string $executionId, array $partial): bool
    {
        if ($this->primary->savePartial($executionId, $partial)) {
            return true;
        }

        if ($this->fallback->savePartial($executionId, $partial)) {
            error_log('Execution report partial fallback repository used for execution ' . $executionId);
            return true;
        }

        return false;
    }
}
