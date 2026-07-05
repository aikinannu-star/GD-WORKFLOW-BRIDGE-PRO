<?php

// Operational validation for repeated gateway + assistant shutdown cycles.

define('SERVICE_HELPERS_TEST_MODE', true);
define('GATEWAY_TEST_MODE', true);
define('ASSISTANT_TEST_MODE', true);
define('ASSISTANT_ALLOW_TEST_ENDPOINTS', '1');

$_ENV['GDWB_DATA_BASE'] = __DIR__ . '/../../tmp/composite_shutdown_endurance';
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

function resetShutdownManagers(): void
{
    $GLOBALS['GATEWAY_SHUTDOWN_MANAGER'] = new GracefulShutdownManager('gateway', 1);
    $GLOBALS['GATEWAY_SHUTDOWN_MANAGER']->registerSignalHandlers();
    global $shutdownManager;
    $shutdownManager = new GracefulShutdownManager(ASSISTANT_SERVICE_NAME, 1);
    $shutdownManager->registerSignalHandlers();
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
setGatewayProxyHandler(buildGatewayProxyHandler());

$apiKey = 'gateway-endurance-key';
ServiceHelpers::saveJson('gateway', 'api_keys.json', [
    $apiKey => [
        'id' => 'endurance-key',
        'tenant_id' => 'endurance-tenant',
        'scopes' => ['assistant:*'],
        'owner' => 'operational-validation',
    ],
]);

$cycles = 5;
for ($cycle = 1; $cycle <= $cycles; $cycle += 1) {
    resetShutdownManagers();

    $assistantManager = $shutdownManager;
    $gatewayManager = $GLOBALS['GATEWAY_SHUTDOWN_MANAGER'];

    $assistantCallbacks = 0;
    $assistantManager->onShutdown(function () use (&$assistantCallbacks): void {
        $assistantCallbacks += 1;
    });

    $gatewayCallbacks = 0;
    $gatewayManager->onShutdown(function () use (&$gatewayCallbacks): void {
        $gatewayCallbacks += 1;
    });

    $response = dispatchGatewayRequest([
        'method' => 'POST',
        'uri' => '/api/v1/assistant/sessions',
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Api-Key' => $apiKey,
        ],
        'body' => json_encode(['user_id' => "endurance-cycle-{$cycle}"]),
    ]);
    assertTrue($response['status'] === 201, "Cycle {$cycle}: expected gateway->assistant session creation to succeed");

    $gatewayManager->beginRequest();
    $assistantManager->beginRequest();

    $gatewayManager->requestShutdown("cycle_{$cycle}");
    $assistantManager->requestShutdown("cycle_{$cycle}");

    assertTrue(!$gatewayManager->canAcceptRequests(), "Cycle {$cycle}: gateway should reject new requests after shutdown begins");
    assertTrue(!$assistantManager->canAcceptRequests(), "Cycle {$cycle}: assistant should reject new requests after shutdown begins");

    $response = dispatchGatewayRequest([
        'method' => 'POST',
        'uri' => '/api/v1/assistant/sessions',
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Api-Key' => $apiKey,
        ],
        'body' => json_encode(['user_id' => "rejected-after-shutdown-{$cycle}"]),
    ]);
    assertTrue($response['status'] === 503, "Cycle {$cycle}: expected gateway requests to be rejected during drain");

    $response = dispatchAssistantRequest([
        'method' => 'POST',
        'uri' => '/api/v1/assistant/sessions',
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode(['user_id' => "assistant-rejected-after-shutdown-{$cycle}"]),
    ]);
    assertTrue($response['status'] === 503, "Cycle {$cycle}: expected direct assistant requests to be rejected during drain");

    $gatewayMetrics = ServiceHelpers::renderPrometheusMetrics('gateway');
    assertContains('gateway_shutdown_in_progress 1.000000', $gatewayMetrics, "Cycle {$cycle}: expected gateway shutdown gauge during drain");
    assertContains('gateway_active_requests 1.000000', $gatewayMetrics, "Cycle {$cycle}: expected gateway active request gauge during simulated in-flight request");

    $assistantMetrics = ServiceHelpers::renderPrometheusMetrics('assistant');
    assertContains('assistant_shutdown_in_progress 1.000000', $assistantMetrics, "Cycle {$cycle}: expected assistant shutdown gauge during drain");
    assertContains('assistant_active_requests 1.000000', $assistantMetrics, "Cycle {$cycle}: expected assistant active request gauge during simulated in-flight request");

    $assistantManager->endRequest();
    $gatewayManager->endRequest();

    assertTrue($assistantCallbacks === 1, "Cycle {$cycle}: assistant shutdown callback should execute exactly once");
    assertTrue($gatewayCallbacks === 1, "Cycle {$cycle}: gateway shutdown callback should execute exactly once");

    $gatewayMetrics = ServiceHelpers::renderPrometheusMetrics('gateway');
    assertContains('gateway_shutdown_in_progress 0.000000', $gatewayMetrics, "Cycle {$cycle}: expected gateway shutdown gauge to reset after drain completion");
    assertContains('gateway_active_requests 0.000000', $gatewayMetrics, "Cycle {$cycle}: expected gateway active request gauge to reset after completion");

    $assistantMetrics = ServiceHelpers::renderPrometheusMetrics('assistant');
    assertContains('assistant_shutdown_in_progress 0.000000', $assistantMetrics, "Cycle {$cycle}: expected assistant shutdown gauge to reset after drain completion");
    assertContains('assistant_active_requests 0.000000', $assistantMetrics, "Cycle {$cycle}: expected assistant active request gauge to reset after completion");
}

$gatewayMetrics = ServiceHelpers::renderPrometheusMetrics('gateway');
assertContains('gateway_shutdown_in_progress_total ' . $cycles, $gatewayMetrics, 'Expected gateway shutdown counter to accumulate across cycles');

$assistantMetrics = ServiceHelpers::renderPrometheusMetrics('assistant');
assertContains('assistant_shutdown_in_progress_total ' . $cycles, $assistantMetrics, 'Expected assistant shutdown counter to accumulate across cycles');

fwrite(STDOUT, "Composite shutdown repeat-cycle validation test passed\n");
