<?php

require_once __DIR__ . '/../execution/ExecutionReport.php';
require_once __DIR__ . '/../execution/ExecutionReportRepositoryInterface.php';
require_once __DIR__ . '/../execution/FileExecutionReportRepository.php';
require_once __DIR__ . '/../execution/FallbackExecutionReportRepository.php';

class FailingExecutionReportRepository implements ExecutionReportRepositoryInterface
{
    public function save(array $report): bool
    {
        return false;
    }

    public function savePartial(string $executionId, array $partial): bool
    {
        return false;
    }
}

$tempDir = sys_get_temp_dir() . '/gdwb-exec-reports-' . uniqid('', true);
if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create temp directory for resilience test');
}

$primary = new FailingExecutionReportRepository();
$fallback = new FileExecutionReportRepository($tempDir);
$repo = new FallbackExecutionReportRepository($primary, $fallback);

$report = new ExecutionReport('resilience-test');
$report->setOutput(['success' => false, 'errorType' => 'timeout']);
$report->setTraceId('trace-123');
$report->setRequestId('req-123');

if (!$repo->save($report->toArray())) {
    fwrite(STDERR, "Fallback repository should write to the fallback store when the primary repository fails\n");
    exit(1);
}

$files = glob($tempDir . '/*.json');
if (empty($files)) {
    fwrite(STDERR, "Fallback repository did not create a fallback report file\n");
    exit(1);
}

$report->markFailure('timeout', 'provider_timeout');
if (($report->toArray()['output']['errorType'] ?? null) !== 'provider_timeout') {
    fwrite(STDERR, "ExecutionReport should record a structured failure reason\n");
    exit(1);
}

fwrite(STDOUT, "Execution report resilience test passed\n");
