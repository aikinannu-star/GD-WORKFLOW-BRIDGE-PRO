<?php

require_once __DIR__ . '/../execution/ExecutionReportService.php';
require_once __DIR__ . '/../execution/FileExecutionReportRepository.php';
require_once __DIR__ . '/../execution/ExecutionReport.php';
require_once __DIR__ . '/../execution/DefaultUsageEstimator.php';
require_once __DIR__ . '/../execution/DefaultCostCalculator.php';
require_once __DIR__ . '/../../dispatcher/events/RuntimeEventEmitter.php';

$tempDir = sys_get_temp_dir() . '/gdwb_execution_report_test_' . uniqid();
if (!is_dir($tempDir) && !@mkdir($tempDir, 0777, true)) {
    echo "Unable to create temp directory: $tempDir" . PHP_EOL;
    exit(1);
}

require_once __DIR__ . '/../execution/AIUsageServiceInterface.php';
require_once __DIR__ . '/../execution/DefaultAIUsageService.php';
require_once __DIR__ . '/../execution/ProviderMetadataRegistry.php';

$repo = new FileExecutionReportRepository($tempDir);
$emitter = new RuntimeEventEmitter();
$usageEstimator = new DefaultUsageEstimator(1.0);
$costCalculator = new DefaultCostCalculator(0.001, 0.002);
$metadataRegistry = new ProviderMetadataRegistry();
$aiUsageService = new DefaultAIUsageService($usageEstimator, $costCalculator, $metadataRegistry);
$service = new ExecutionReportService($repo, $emitter, $aiUsageService);
$report = new ExecutionReport('exec_test_' . uniqid());
$service->start($report);

$emitter->emit('assistant.provider.request.completed', [
    'executionId' => $report->getExecutionId(),
    'provider' => 'TestProvider',
    'model' => 'test-model',
    'tenantId' => 'tenant-test',
    'assistantId' => 'assistant-test',
    'duration_ms' => 5,
    'response_length' => 2,
    'prompt_tokens' => 0,
    'completion_tokens' => 0,
    'prompt' => 'hello world',
    'completion' => 'ok',
    'provider_metadata' => [
        'providerName' => 'TestProvider',
        'model' => 'test-model',
        'pricingProfile' => [
            'currency' => 'USD',
            'prompt_per_1k' => 0.001,
            'completion_per_1k' => 0.002,
        ],
    ],
    'trace' => [],
    'requestId' => 'request-1',
]);

$partialFile = $tempDir . '/' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $report->getExecutionId()) . '.partial.json';
if (!file_exists($partialFile)) {
    echo "Expected partial report file to exist: $partialFile" . PHP_EOL;
    exit(1);
}

$partial = json_decode(file_get_contents($partialFile), true);
if (!is_array($partial)) {
    echo "Expected partial report JSON to decode." . PHP_EOL;
    exit(1);
}

if (($partial['llmUsage']['promptTokens'] ?? 0) !== 2) {
    echo "Expected estimated prompt tokens to be 2, got " . var_export($partial['llmUsage']['promptTokens'], true) . PHP_EOL;
    exit(1);
}

if (($partial['usageSource'] ?? '') !== 'estimated') {
    echo "Expected usageSource to be estimated, got " . var_export($partial['usageSource'] ?? null, true) . PHP_EOL;
    exit(1);
}

if (($partial['costSource'] ?? '') !== 'provider_pricing') {
    echo "Expected costSource to be provider_pricing, got " . var_export($partial['costSource'] ?? null, true) . PHP_EOL;
    exit(1);
}

$service->finish($report);
$finalMatches = glob($tempDir . '/' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $report->getExecutionId()) . '_*.json');
if (count($finalMatches) !== 1) {
    echo "Expected a single final report file, found " . count($finalMatches) . PHP_EOL;
    exit(1);
}

$final = json_decode(file_get_contents($finalMatches[0]), true);
if (($final['llmUsage']['promptTokens'] ?? 0) !== 2) {
    echo "Expected final report promptTokens to remain 2, got " . var_export($final['llmUsage']['promptTokens'] ?? null, true) . PHP_EOL;
    exit(1);
}

if (($final['usageSource'] ?? '') !== 'estimated') {
    echo "Expected final report usageSource to be estimated, got " . var_export($final['usageSource'] ?? null, true) . PHP_EOL;
    exit(1);
}

if (($final['costSource'] ?? '') !== 'provider_pricing') {
    echo "Expected final report costSource to be provider_pricing, got " . var_export($final['costSource'] ?? null, true) . PHP_EOL;
    exit(1);
}

if (($final['tools'] ?? []) !== []) {
    echo "Expected final report tools list to be empty." . PHP_EOL;
    exit(1);
}

if (($final['provider']['provider'] ?? '') !== 'TestProvider') {
    echo "Expected final report provider.provider to be TestProvider, got " . var_export($final['provider']['provider'] ?? null, true) . PHP_EOL;
    exit(1);
}

if (($final['provider']['model'] ?? '') !== 'test-model') {
    echo "Expected final report provider.model to be test-model, got " . var_export($final['provider']['model'] ?? null, true) . PHP_EOL;
    exit(1);
}

echo 'Execution report service test passed' . PHP_EOL;
