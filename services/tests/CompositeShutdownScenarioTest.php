<?php

// Composite graceful shutdown scenario across gateway + assistant.

define('SERVICE_HELPERS_TEST_MODE', true);
define('GATEWAY_TEST_MODE', true);
define('ASSISTANT_TEST_MODE', true);
define('ASSISTANT_ALLOW_TEST_ENDPOINTS', '1');

$_ENV['GDWB_DATA_BASE'] = __DIR__ . '/../../tmp/composite_shutdown_data';
putenv('GDWB_DATA_BASE=' . $_ENV['GDWB_DATA_BASE']);
$_ENV['GATEWAY_ASSISTANT_BASE'] = 'http://assistant-service:8017';
putenv('GATEWAY_ASSISTANT_BASE=' . $_ENV['GATEWAY_ASSISTANT_BASE']);
$_ENV['ASSISTANT_ALLOW_TEST_ENDPOINTS'] = '1';
putenv('ASSISTANT_ALLOW_TEST_ENDPOINTS=1');

require_once __DIR__ . '/../assistant/server.php';
require_once __DIR__ . '/../gateway/server.php';

function resetTestData(): void
{
    $base = $_ENV['GDWB_DATA_BASE'];
    if (!is_dir($base)) {
        mkdir($base, 0777, true);
    }

    $files = [
        ServiceHelpers::dataPath('assistant', 'metrics.json'),
        ServiceHelpers::dataPath('assistant', 'sessions.json'),
        ServiceHelpers::dataPath('gateway', 'metrics.json'),
        ServiceHelpers::dataPath('gateway', 'api_keys.json'),
        ServiceHelpers::dataPath('gateway', 'rate_limits.json'),
        ServiceHelpers::dataPath('gateway', 'requests.log'),
    ];
    foreach ($files as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
}

function buildServerHeaders(array $requestHeaders): void
{
    foreach (array_keys($_SERVER) as $key) {
        if (strpos($key, 'HTTP_') === 0) {
            unset($_SERVER[$key]);
        }
    }

    foreach ($requestHeaders as $name => $value) {
        $serverKey = 'HTTP_' . str_replace('-', '_', strtoupper($name));
        $_SERVER[$serverKey] = $value;
    }
}

function clearServerGlobals(): void
{
    $_SERVER = [];
    if (function_exists('header_remove')) {
        header_remove();
    }
}

function dispatchAssistantRequest(array $request): array
{
    clearServerGlobals();
    $_SERVER['REQUEST_METHOD'] = $request['method'];
    $_SERVER['REQUEST_URI'] = $request['uri'];
    $_SERVER['QUERY_STRING'] = $request['query'] ?? '';
    $_SERVER['REMOTE_ADDR'] = $request['remote_addr'] ?? '127.0.0.1';
    $_SERVER['GDWB_RAW_REQUEST_BODY'] = $request['body'] ?? '';
    buildServerHeaders($request['headers'] ?? []);

    try {
        return runAssistantServer();
    } catch (ServiceHelpersTestResponseException $ex) {
        return $ex->response;
    }
}

function dispatchGatewayRequest(array $request): array
{
    clearServerGlobals();
    $_SERVER['REQUEST_METHOD'] = $request['method'];
    $_SERVER['REQUEST_URI'] = $request['uri'];
    $_SERVER['QUERY_STRING'] = $request['query'] ?? '';
    $_SERVER['REMOTE_ADDR'] = $request['remote_addr'] ?? '127.0.0.1';
    $_SERVER['GDWB_RAW_REQUEST_BODY'] = $request['body'] ?? '';
    buildServerHeaders($request['headers'] ?? []);

    try {
        return runGatewayServer();
    } catch (ServiceHelpersTestResponseException $ex) {
        return $ex->response;
    }
}

function buildGatewayProxyHandler(): callable
{
    return function (string $targetUrl, string $method, array $headers, string $body = null): array {
        $parsed = parse_url($targetUrl);
        if ($parsed === false || empty($parsed['path'])) {
            return ['status' => 502, 'headers' => ['Content-Type: application/json'], 'body' => json_encode(['error' => 'invalid_target_url'])];
        }
        $uri = $parsed['path'];
        if (!empty($parsed['query'])) {
            $uri .= '?' . $parsed['query'];
        }

        $requestHeaders = [];
        foreach ($headers as $line) {
            if (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $requestHeaders[trim($name)] = trim($value);
            }
        }

        $response = dispatchAssistantRequest([
            'method' => $method,
            'uri' => $uri,
            'headers' => $requestHeaders,
            'body' => $body,
        ]);

        return [
            'status' => $response['status'] ?? 500,
            'headers' => $response['headers'] ?? [],
            'body' => $response['body'] ?? '',
        ];
    };
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

resetTestData();

$apiKey = 'gateway-test-key';
ServiceHelpers::saveJson('gateway', 'api_keys.json', [
    $apiKey => [
        'id' => 'test-key',
        'tenant_id' => 'test-tenant',
        'scopes' => ['assistant:*'],
        'owner' => 'test-suite',
    ],
]);

setGatewayProxyHandler(buildGatewayProxyHandler());

$gatewayManager = $GLOBALS['GATEWAY_SHUTDOWN_MANAGER'];
$assistantManager = $GLOBALS['shutdownManager'];

$assistantShutdownCallbacks = 0;
$assistantManager->onShutdown(function () use (&$assistantShutdownCallbacks): void {
    $assistantShutdownCallbacks += 1;
});

$gatewayShutdownCallbacks = 0;
$gatewayManager->onShutdown(function () use (&$gatewayShutdownCallbacks): void {
    $gatewayShutdownCallbacks += 1;
});

$response = dispatchGatewayRequest([
    'method' => 'POST',
    'uri' => '/api/v1/assistant/sessions',
    'headers' => [
        'Content-Type' => 'application/json',
        'X-Api-Key' => $apiKey,
    ],
    'body' => json_encode(['user_id' => 'composite-test']),
]);
assertTrue($response['status'] === 201, 'Expected initial gateway->assistant session creation to succeed');

$gatewayManager->beginRequest();
$assistantManager->beginRequest();

assertTrue($gatewayManager->canAcceptRequests(), 'Gateway should still accept requests before shutdown');
assertTrue($assistantManager->canAcceptRequests(), 'Assistant should still accept requests before shutdown');

$gatewayManager->requestShutdown('composite_test');
$assistantManager->requestShutdown('composite_test');

assertTrue(!$gatewayManager->canAcceptRequests(), 'Gateway should reject new requests after shutdown begins');
assertTrue(!$assistantManager->canAcceptRequests(), 'Assistant should reject new requests after shutdown begins');

$gatewayMetrics = ServiceHelpers::renderPrometheusMetrics('gateway');
assertContains('gateway_shutdown_in_progress 1.000000', $gatewayMetrics, 'Gateway shutdown-in-progress metric should be set');
assertContains('gateway_active_requests 1.000000', $gatewayMetrics, 'Gateway active request gauge should be set during drain');

$assistantMetrics = ServiceHelpers::renderPrometheusMetrics('assistant');
assertContains('assistant_shutdown_in_progress 1.000000', $assistantMetrics, 'Assistant shutdown-in-progress metric should be set');
assertContains('assistant_active_requests 1.000000', $assistantMetrics, 'Assistant active request gauge should be set during drain');

$response = dispatchGatewayRequest([
    'method' => 'POST',
    'uri' => '/api/v1/assistant/sessions',
    'headers' => [
        'Content-Type' => 'application/json',
        'X-Api-Key' => $apiKey,
    ],
    'body' => json_encode(['user_id' => 'rejected-after-shutdown']),
]);
assertTrue($response['status'] === 503, 'Expected new gateway requests to be rejected with 503 during drain');

$gatewayMetrics = ServiceHelpers::renderPrometheusMetrics('gateway');
assertContains('gateway_requests_rejected_during_shutdown_total 1', $gatewayMetrics, 'Gateway rejected request counter should increment during shutdown');

$response = dispatchAssistantRequest([
    'method' => 'POST',
    'uri' => '/api/v1/assistant/sessions',
    'headers' => [
        'Content-Type' => 'application/json',
    ],
    'body' => json_encode(['user_id' => 'assistant-rejected-during-shutdown']),
]);
assertTrue($response['status'] === 503, 'Expected direct assistant requests to be rejected with 503 during drain');

$assistantMetrics = ServiceHelpers::renderPrometheusMetrics('assistant');
assertContains('assistant_requests_rejected_during_shutdown_total 1', $assistantMetrics, 'Assistant rejected request counter should increment during shutdown');

$assistantManager->endRequest();
$gatewayManager->endRequest();

assertTrue($assistantShutdownCallbacks === 1, 'Assistant shutdown callback should execute exactly once');
assertTrue($gatewayShutdownCallbacks === 1, 'Gateway shutdown callback should execute exactly once');

$assistantMetrics = ServiceHelpers::renderPrometheusMetrics('assistant');
assertContains('assistant_shutdown_in_progress 0.000000', $assistantMetrics, 'Assistant shutdown gauge should reset after drain completion');
assertContains('assistant_active_requests 0.000000', $assistantMetrics, 'Assistant active request gauge should reset after completion');

$gatewayMetrics = ServiceHelpers::renderPrometheusMetrics('gateway');
assertContains('gateway_shutdown_in_progress 0.000000', $gatewayMetrics, 'Gateway shutdown gauge should reset after drain completion');
assertContains('gateway_active_requests 0.000000', $gatewayMetrics, 'Gateway active request gauge should reset after completion');

// Failure-oriented timeout variation
$assistantTimeoutManager = new GracefulShutdownManager('assistant_timeout', 1);
$assistantTimeoutExceeded = 0;
$assistantTimeoutManager->onShutdown(function () use (&$assistantTimeoutExceeded): void {
    $assistantTimeoutExceeded += 1;
});
$assistantTimeoutManager->beginRequest();
$assistantTimeoutManager->requestShutdown('timeout_test');
$assistantTimeoutManager->handleShutdownTimeout();

assertTrue($assistantTimeoutExceeded === 1, 'Assistant timeout shutdown should execute exactly once after forced timeout');

$assistantTimeoutMetrics = ServiceHelpers::renderPrometheusMetrics('assistant_timeout');
assertContains('assistant_timeout_shutdown_forced_total', $assistantTimeoutMetrics, 'Assistant timeout forced shutdown metric should be emitted');
assertContains('assistant_timeout_shutdown_in_progress 0.000000', $assistantTimeoutMetrics, 'Assistant timeout shutdown gauge should reset after forced shutdown');

$gatewayTimeoutManager = new GracefulShutdownManager('gateway_timeout', 1);
$gatewayTimeoutExceeded = 0;
$gatewayTimeoutManager->onShutdown(function () use (&$gatewayTimeoutExceeded): void {
    $gatewayTimeoutExceeded += 1;
});
$gatewayTimeoutManager->beginRequest();
$gatewayTimeoutManager->requestShutdown('timeout_test');
$gatewayTimeoutManager->handleShutdownTimeout();

assertTrue($gatewayTimeoutExceeded === 1, 'Gateway timeout shutdown should execute exactly once after forced timeout');

$gatewayTimeoutMetrics = ServiceHelpers::renderPrometheusMetrics('gateway_timeout');
assertContains('gateway_timeout_shutdown_forced_total', $gatewayTimeoutMetrics, 'Gateway timeout forced shutdown metric should be emitted');
assertContains('gateway_timeout_shutdown_in_progress 0.000000', $gatewayTimeoutMetrics, 'Gateway timeout shutdown gauge should reset after forced shutdown');

fwrite(STDOUT, "Composite assistant+gateway graceful shutdown test passed\n");
