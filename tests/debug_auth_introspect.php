<?php
$base = 'http://127.0.0.1:8000';

function post($url, $data) {
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($data),
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ];
    $context = stream_context_create($opts);
    $resp = @file_get_contents($url, false, $context);
    $code = 0;
    $hdrs = $http_response_header ?? [];
    if (!empty($hdrs) && preg_match('#HTTP/[^ ]+\s+(\d+)#', $hdrs[0], $m)) {
        $code = (int)$m[1];
    }
    return [$code, $resp];
}

list($code, $resp) = post($base . '/api/v1/auth/register', ['tenant_id' => 'ci-tenant', 'email' => 'debug-introspect2@example.com', 'password' => 'password123']);
echo "REG CODE: $code\n";
echo "REG RESP: $resp\n";
$js = json_decode($resp, true) ?? [];
$token = $js['token'] ?? null;
echo "TOKEN: " . ($token ?: '(none)') . "\n";

list($c2, $r2) = post('http://127.0.0.1:8002/api/v1/auth/introspect', ['token' => $token]);
echo "INTROSPECT CODE: $c2\n";
echo "INTROSPECT RESP: $r2\n";
