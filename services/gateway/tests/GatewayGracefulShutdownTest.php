<?php

define('SERVICE_HELPERS_TEST_MODE', true);
define('GATEWAY_TEST_MODE', true);

require_once __DIR__ . '/../server.php';

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

function dispatchGatewayRequest(array $request): array
{
    foreach (['REQUEST_METHOD', 'REQUEST_URI', 'QUERY_STRING', 'REMOTE_ADDR', 'GDWB_RAW_REQUEST_BODY'] as $key) {
        unset($_SERVER[$key]);
    }
    buildServerHeaders($request['headers'] ?? []);
    $_SERVER['REQUEST_METHOD'] = $request['method'];
    $_SERVER['REQUEST_URI'] = $request['uri'];
    $_SERVER['QUERY_STRING'] = $request['query'] ?? '';
    $_SERVER['REMOTE_ADDR'] = $request['remote_addr'] ?? '127.0.0.1';
    $_SERVER['GDWB_RAW_REQUEST_BODY'] = $request['body'] ?? '';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    try {
        return runGatewayServer();
    } catch (ServiceHelpersTestResponseException $ex) {
        return $ex->response;
    }
}

function resetGatewayMetrics(): void
{
    $metricsPath = ServiceHelpers::dataPath('gateway', 'metrics.json');
    if (file_exists($metricsPath)) {
        unlink($metricsPath);
    }
}

setGatewayProxyHandler(function (string $targetUrl, string $method, array $headers, string $body = null): array {
    return [
        'status' => 200,
        'headers' => ['Content-Type: application/json'],
        'body' => json_encode(['ok' => true, 'target' => $targetUrl]),
    ];
});

resetGatewayMetrics();

$manager = $GLOBALS['GATEWAY_SHUTDOWN_MANAGER'];

$response = dispatchGatewayRequest([
    'method' => 'GET',
    'uri' => '/api/v1/license/status',
    'headers' => [],
]);

if (($response['status'] ?? 0) !== 200) {
    fwrite(STDERR, "Expected 200 before drain, got {$response['status']}\n");
    exit(1);
}

$manager->beginDrain();

$response = dispatchGatewayRequest([
    'method' => 'GET',
    'uri' => '/api/v1/license/status',
    'headers' => [],
]);

if (($response['status'] ?? 0) !== 503) {
    fwrite(STDERR, "Expected 503 during drain, got {$response['status']}\n");
    exit(1);
}

$metricsText = ServiceHelpers::renderPrometheusMetrics('gateway');
if (strpos($metricsText, 'gateway_requests_rejected_during_shutdown_total') === false) {
    fwrite(STDERR, "Expected shutdown rejection metric to be recorded\n");
    exit(1);
}

$callbackExecuted = false;
$manager->beginRequest();
$manager->beginRequest();
$manager->onShutdown(function () use (&$callbackExecuted): void {
    $callbackExecuted = true;
});
$manager->requestShutdown('test');

if ($callbackExecuted) {
    fwrite(STDERR, "Shutdown callback should not execute while active requests remain\n");
    exit(1);
}

if ($manager->getActiveRequestCount() !== 2) {
    fwrite(STDERR, "Expected two active requests while shutdown is pending\n");
    exit(1);
}

$manager->endRequest();
$metricsText = ServiceHelpers::renderPrometheusMetrics('gateway');
if (strpos($metricsText, 'gateway_active_requests 1.000000') === false) {
    fwrite(STDERR, "Expected active request gauge to reflect in-flight requests\n");
    exit(1);
}
if (strpos($metricsText, 'gateway_shutdown_in_progress 1.000000') === false) {
    fwrite(STDERR, "Expected shutdown in progress gauge to remain set during drain\n");
    exit(1);
}

if ($callbackExecuted) {
    fwrite(STDERR, "Shutdown callback should still not execute until all active requests complete\n");
    exit(1);
}

$manager->endRequest();

if (!$callbackExecuted) {
    fwrite(STDERR, "Shutdown callback should execute after all active requests complete\n");
    exit(1);
}

$metricsText = ServiceHelpers::renderPrometheusMetrics('gateway');
if (strpos($metricsText, 'gateway_active_requests 0.000000') === false) {
    fwrite(STDERR, "Expected active request gauge to reset after shutdown completion\n");
    exit(1);
}
if (strpos($metricsText, 'gateway_shutdown_in_progress 0.000000') === false) {
    fwrite(STDERR, "Expected shutdown progress gauge to clear after callbacks run\n");
    exit(1);
}

fwrite(STDOUT, "Gateway graceful shutdown coordination test passed\n");
