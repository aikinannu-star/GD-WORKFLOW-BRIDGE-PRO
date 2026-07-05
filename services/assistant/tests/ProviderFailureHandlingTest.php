<?php

require_once __DIR__ . '/../OllamaProvider.php';

$provider = new OllamaProvider([
    'api_url' => 'http://127.0.0.1:1/v1/completions',
    'timeout' => 1,
]);

$result = $provider->generate('ping');
if ($result['success'] !== false) {
    fwrite(STDERR, "Expected unreachable provider to fail generation\n");
    exit(1);
}

$health = $provider->health();
if (($health['status'] ?? '') !== 'unavailable') {
    fwrite(STDERR, "Expected provider health to report unavailable for an unreachable endpoint\n");
    exit(1);
}

fwrite(STDOUT, "Provider failure handling test passed\n");
