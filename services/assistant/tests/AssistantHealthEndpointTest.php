<?php

function runProbe(string $path): array
{
    $probe = __DIR__ . '/AssistantHealthEndpointProbe.php';
    $command = sprintf(
        '%s %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($probe),
        escapeshellarg($path)
    );

    exec($command, $output, $exitCode);
    $body = implode("\n", $output);

    return ['exitCode' => $exitCode, 'body' => $body];
}

function assertJsonResponse(array $response, int $expectedStatus): void
{
    if ($response['exitCode'] !== 0) {
        echo sprintf('Probe command failed with exit code %d: %s\n', $response['exitCode'], $response['body']);
        exit(1);
    }

    $payload = json_decode($response['body'], true);
    if (!is_array($payload)) {
        echo 'Expected JSON response but did not receive valid JSON.\n';
        echo 'Body: ' . $response['body'] . '\n';
        exit(1);
    }

    if (($payload['status'] ?? null) !== $expectedStatus) {
        echo sprintf('Expected status %d but found %s.\n', $expectedStatus, var_export($payload['status'], true));
        echo 'Response: ' . $response['body'] . '\n';
        exit(1);
    }
}

function assertMetricOutput(array $response): void
{
    if ($response['exitCode'] !== 0) {
        echo sprintf('Probe command failed with exit code %d: %s\n', $response['exitCode'], $response['body']);
        exit(1);
    }

    if (strpos($response['body'], 'assistant_health_live_checks_total') === false && strpos($response['body'], 'assistant_health_ready_checks_total') === false) {
        echo 'Expected Prometheus metrics text, but metrics output did not contain the expected assistant health metric names.\n';
        echo 'Body: ' . $response['body'] . '\n';
        exit(1);
    }
}

$liveResponse = runProbe('/health/live');
assertJsonResponse($liveResponse, 200);

$readyResponse = runProbe('/health/ready');
assertJsonResponse($readyResponse, 200);

$metricsResponse = runProbe('/metrics');
assertMetricOutput($metricsResponse);

echo 'Assistant health and observability endpoints test passed' . PHP_EOL;
