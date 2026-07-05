<?php

// Probe script for assistant health and metrics endpoints.
define('SERVICE_HELPERS_TEST_MODE', true);
putenv('ASSISTANT_PROVIDER=ollama');
putenv('ASSISTANT_LLM_API_URL=http://localhost:11434/v1/completions');
putenv('ASSISTANT_LLM_HEALTH_CHECK=0');
putenv('GDWB_DATA_BASE=' . __DIR__ . '/tmp');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $argv[1] ?? '/health';

try {
    require_once __DIR__ . '/../server.php';
} catch (ServiceHelpersTestResponseException $e) {
    echo json_encode($e->response);
    exit(0);
}

// If the route does not use test-mode response throwing, echo a fallback marker.
echo json_encode(['status' => 'unknown', 'body' => null]);
exit(0);
