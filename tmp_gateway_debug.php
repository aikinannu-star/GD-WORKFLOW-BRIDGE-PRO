<?php

define('SERVICE_HELPERS_TEST_MODE', true);
define('GATEWAY_TEST_MODE', true);

require_once __DIR__ . '/services/gateway/server.php';

$_SERVER = [
    'REQUEST_METHOD' => 'POST',
    'REQUEST_URI' => '/api/v1/assistant/sessions/test-session/message',
    'QUERY_STRING' => '',
    'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_X_API_KEY' => 'valid-key-1',
    'HTTP_X_TENANT_ID' => 'tenant-allowed',
    'GDWB_RAW_REQUEST_BODY' => json_encode([
        'text' => 'Please execute workflow and trace this request',
        'conversationId' => 'test-conv-001',
        'sessionId' => 'test-session',
    ]),
];

// Remove any existing ob buffers
while (ob_get_level() > 0) {
    ob_end_clean();
}

try {
    $response = runGatewayServer();
    echo "RESPONSE ARRAY:\n";
    var_export($response);
    echo "\n";
} catch (Exception $e) {
    echo "EXCEPTION: " . get_class($e) . " - " . $e->getMessage() . "\n";
    if ($e instanceof ServiceHelpersTestResponseException) {
        var_export($e->response);
        echo "\n";
    }
}
