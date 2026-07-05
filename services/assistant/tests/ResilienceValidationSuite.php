<?php

require_once __DIR__ . '/../OllamaProvider.php';
require_once __DIR__ . '/../execution/ExecutionReport.php';
require_once __DIR__ . '/../execution/ExecutionReportRepositoryInterface.php';
require_once __DIR__ . '/../execution/FileExecutionReportRepository.php';
require_once __DIR__ . '/../execution/FallbackExecutionReportRepository.php';

class FailingRepositoryForSuite implements ExecutionReportRepositoryInterface
{
    public function save(array $report): bool { return false; }
    public function savePartial(string $executionId, array $partial): bool { return false; }
}

$provider = new OllamaProvider(['api_url' => 'http://127.0.0.1:1/v1/completions', 'timeout' => 1]);
$result = $provider->generate('ping');
if (($result['success'] ?? null) !== false) {
    fwrite(STDERR, "Expected timeout scenario to fail cleanly\n");
    exit(1);
}

$health = $provider->health();
if (($health['status'] ?? null) !== 'unavailable') {
    fwrite(STDERR, "Expected provider health to report unavailable after a timeout\n");
    exit(1);
}

$tempDir = sys_get_temp_dir() . '/gdwb-resilience-suite-' . uniqid('', true);
if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create temp directory for resilience suite');
}

$primary = new FailingRepositoryForSuite();
$fallback = new FileExecutionReportRepository($tempDir);
$repo = new FallbackExecutionReportRepository($primary, $fallback);
$report = new ExecutionReport('resilience-suite');
$report->markFailure('provider_timeout', 'provider_timeout');
if (!$repo->save($report->toArray())) {
    fwrite(STDERR, "Fallback repository should persist reports after primary failure\n");
    exit(1);
}

$files = glob($tempDir . '/*.json');
if (empty($files)) {
    fwrite(STDERR, "Fallback repository did not emit a persisted report file\n");
    exit(1);
}

if (($report->toArray()['output']['errorType'] ?? null) !== 'provider_timeout') {
    fwrite(STDERR, "Execution report should capture a structured failure reason\n");
    exit(1);
}

fwrite(STDOUT, "Assistant resilience validation suite passed\n");
