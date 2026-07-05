<?php

require_once __DIR__ . '/../RuntimeBootstrap.php';
require_once __DIR__ . '/../execution/AIUsageServiceInterface.php';

$tempDir = sys_get_temp_dir() . '/gdwb_ai_usage_service_test_' . uniqid();
$options = ['conversation_path' => $tempDir];
$bootstrap = RuntimeBootstrap::bootstrap($options);

if (!isset($bootstrap['registrar']) || !is_object($bootstrap['registrar'])) {
    echo "Expected runtime bootstrap to return a registrar" . PHP_EOL;
    exit(1);
}

$registrar = $bootstrap['registrar'];
$service = $registrar->get('ai_usage_service');
if (!$service instanceof AIUsageServiceInterface) {
    echo "Expected ai_usage_service to be registered and implement AIUsageServiceInterface" . PHP_EOL;
    exit(1);
}

if (!isset($bootstrap['aiUsageService']) || !$bootstrap['aiUsageService'] instanceof AIUsageServiceInterface) {
    echo "Expected bootstrap to include aiUsageService." . PHP_EOL;
    exit(1);
}

echo 'AIUsageService registration test passed' . PHP_EOL;
