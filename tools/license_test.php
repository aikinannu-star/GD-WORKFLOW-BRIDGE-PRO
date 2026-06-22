<?php
// Simple license-server connectivity test script
$health = @file_get_contents('http://127.0.0.1:8001/health');
echo "health: " . ($health ?: "(no response)") . PHP_EOL;

$payload = json_encode(['license_key' => 'TEST-12345678901234567890']);
$opts = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 5]];
$context = stream_context_create($opts);
$resp = @file_get_contents('http://127.0.0.1:8001/api/v1/validate', false, $context);
echo "validate response: " . ($resp ?: "(no response)") . PHP_EOL;

// Try JWKS
$jwks = @file_get_contents('http://127.0.0.1:8001/api/v1/jwks');
echo "jwks: " . ($jwks ?: "(no response)") . PHP_EOL;
