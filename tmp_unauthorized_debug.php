<?php
require_once __DIR__ . '/services/assistant/RuntimeBootstrap.php';

define('SERVICE_HELPERS_TEST_MODE', true);

$runtime = RuntimeBootstrap::bootstrap([
    'dispatcher_plugins_path' => __DIR__ . '/services/dispatcher/plugins',
]);
$assistantManager = $runtime['runtime']->assistantManager;

$_SERVER['HTTP_X_TENANT_ID'] = 'tenant-denied';

$result = $assistantManager->handle('support-assistant', [
    'message' => 'Please execute workflow',
    'conversationId' => 'test-conv',
    'sessionId' => 'sess',
    'tenantId' => 'tenant-denied',
    'userId' => 'test-user',
]);

echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
