<?php

require_once __DIR__ . '/../OllamaProvider.php';

$provider = new OllamaProvider([
    'max_retries' => 2,
    'retry_delay_ms' => 0,
]);

if (!$provider->shouldRetryRequest(['error' => 'temporarily_unavailable', 'http_code' => 503])) {
    fwrite(STDERR, "Expected transient provider failures to be retried\n");
    exit(1);
}

if ($provider->shouldRetryRequest(['error' => 'invalid_request_payload', 'http_code' => 400])) {
    fwrite(STDERR, "Expected invalid requests not to be retried\n");
    exit(1);
}

fwrite(STDOUT, "Retry behavior test passed\n");
