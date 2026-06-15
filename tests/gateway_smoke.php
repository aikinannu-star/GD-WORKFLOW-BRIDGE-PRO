<?php
$base = getenv('BASE') ?: 'http://127.0.0.1:8000';

function http_request($url, $method = 'GET', $body = null, $headers = []) {
    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ];
    $context = stream_context_create($opts);
    $resp = @file_get_contents($url, false, $context);
    $status = 0;
    $respHeaders = $http_response_header ?? [];
    if (!empty($respHeaders) && preg_match('#HTTP/[^ ]+\s+(\d+)#', $respHeaders[0], $m)) {
        $status = (int)$m[1];
    }
    return ['status' => $status, 'headers' => $respHeaders, 'body' => $resp];
}

echo "Waiting for gateway health...\n";
$ok = false;
for ($i = 0; $i < 30; $i++) {
    $r = http_request($base . '/health');
    if ($r['status'] === 200) { $ok = true; break; }
    sleep(1);
}
if (!$ok) { echo "gateway health failed\n"; exit(1); }

echo "Checking license health...\n";
$r = http_request($base . '/api/v1/license/health');
if ($r['status'] !== 200) { echo "license health failed\n"; exit(1); }

echo "Checking openapi...\n";
$r = http_request($base . '/api/v1/license/openapi.yaml');
$firstLine = preg_split("/\r?\n/", $r['body'])[0] ?? '';
if ($r['status'] !== 200 || !preg_match('/^\s*openapi:/i', $firstLine)) { echo "openapi not found\n"; exit(1); }

echo "Checking tenant protected returns 401 without token...\n";
$r = http_request($base . '/api/v1/tenant/health');
if ($r['status'] !== 401) { echo "expected 401, got {$r['status']}\n"; exit(1); }

echo "Registering test user...\n";
$payload = json_encode(['tenant_id' => 'ci-tenant', 'email' => 'ci@example.com', 'password' => 'password123']);
$r = http_request($base . '/api/v1/auth/register', 'POST', $payload, ['Content-Type: application/json']);
if ($r['status'] !== 200 && $r['status'] !== 201) { echo "register failed: {$r['status']}\n"; echo $r['body'] . "\n"; exit(1); }
$js = json_decode($r['body'], true) ?? [];
$token = $js['token'] ?? null;
if (empty($token)) { echo "failed to get token\n"; echo $r['body'] . "\n"; exit(1); }

echo "Checking tenant health with token...\n";
$r = http_request($base . '/api/v1/tenant/health', 'GET', null, ['Authorization: Bearer ' . $token]);
if ($r['status'] !== 200) { echo "tenant health with auth failed: {$r['status']}\n"; exit(1); }

echo "Checking aggregate health contains license...\n";
$r = http_request($base . '/health/services');
$js = json_decode($r['body'], true) ?? [];
if (!isset($js['services']['license'])) { echo "aggregate missing license\n"; exit(1); }

echo "All gateway smoke tests passed.\n";
return 0;
