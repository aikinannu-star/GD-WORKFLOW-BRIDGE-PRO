<?php
$base = getenv('BASE') ?: 'http://127.0.0.1:8000';

function req($method, $path, $body = null, $headers = []) {
    $opts = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body, 'ignore_errors' => true, 'timeout' => 5]];
    $ctx = stream_context_create($opts);
    $res = @file_get_contents($base . $path, false, $ctx);
    $status = 0; $hdrs = $http_response_header ?? [];
    if (!empty($hdrs) && preg_match('#HTTP/[^ ]+\s+(\d+)#', $hdrs[0], $m)) $status = (int)$m[1];
    return [$status, $res, $hdrs];
}

// register user to get a token
list($s, $r) = req('POST', '/api/v1/auth/register', json_encode(['tenant_id'=>'ci-tenant','email'=>'quota-test@example.com','password'=>'password123']), ['Content-Type: application/json']);
if ($s !== 200 && $s !== 201) { echo "register failed $s\n$r\n"; exit(1); }
$js = json_decode($r, true); $token = $js['token'] ?? null; if (!$token) { echo "no token\n"; exit(1); }

// Do N requests to a protected endpoint, where tenant limit is 60 (from services/data/tenant_quotas.json)
$limit = 60; $succeeded = 0; $failed = 0;
for ($i=0; $i<($limit+5); $i++) {
    list($st, $body, $hdrs) = req('GET', '/api/v1/tenant/health', null, ["Authorization: Bearer $token"]);
    if ($st === 200) $succeeded++; else $failed++;
    if ($st === 429) {
        echo "quota enforced at iteration $i\n"; break;
    }
}

if ($failed > 0) {
    echo "failed: $failed, succeeded: $succeeded\n";
} else {
    echo "no failures, succeeded: $succeeded\n";
}
