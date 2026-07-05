<?php

require_once __DIR__ . '/../ModelProviderInterface.php';
require_once __DIR__ . '/../OllamaProvider.php';
require_once __DIR__ . '/../LocalModelProvider.php';
require_once __DIR__ . '/../ProviderRequestHeaders.php';

$providers = [
    'OllamaProvider' => new OllamaProvider(['api_url' => 'http://localhost:9999/v1/completions', 'timeout' => 1]),
    'LocalModelProvider' => new LocalModelProvider(),
];

$options = [
    'trace' => [
        'trace_id' => '0123456789abcdef0123456789abcdef',
        'span_id' => '0123456789abcdef',
        'parent_span_id' => 'fedcba9876543210',
    ],
    'request_id' => 'req-123',
    'tenant_id' => 'tenant-xyz',
    'assistant_id' => 'assistant-abc',
    'conversation_id' => 'conv-456',
    'workflow_id' => 'workflow-789',
    'execution_id' => 'exec-101112',
    'capture_request_headers' => true,
];

$expectedHeaders = ProviderRequestHeaders::build($options);
if (empty($expectedHeaders['X-Trace-Id']) || empty($expectedHeaders['X-Request-Id'])) {
    echo 'Expected helper to return required trace/request headers' . PHP_EOL;
    exit(1);
}

foreach ($providers as $providerName => $provider) {
    $result = $provider->chat('Hello', $options);
    if (!is_array($result)) {
        echo "{$providerName} did not return an array" . PHP_EOL;
        exit(1);
    }

    $headers = $result['headers'] ?? null;
    if (!is_array($headers)) {
        echo "{$providerName} did not expose captured headers" . PHP_EOL;
        exit(1);
    }

    foreach ($expectedHeaders as $name => $value) {
        if (!isset($headers[$name]) || (string)$headers[$name] !== (string)$value) {
            echo "{$providerName} header mismatch for {$name}: expected {$value}, got " . var_export($headers[$name], true) . PHP_EOL;
            exit(1);
        }
    }
}

echo 'Provider trace propagation test passed' . PHP_EOL;
