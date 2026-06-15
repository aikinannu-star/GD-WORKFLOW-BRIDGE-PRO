<?php
// Debug helper: issue a license token via the API gateway, print token and raw responses,
// revoke via admin, and show post-revoke introspect response for troubleshooting.
$gateway = getenv('GATEWAY_URL') ?: 'http://127.0.0.1:3000';
$license_server = getenv('LICENSE_SERVER_URL') ?: 'http://127.0.0.1:8001';

function postJsonRaw($url, $data, $headers = []) {
    $payload = json_encode($data);
    $hdrs = array_merge(['Content-Type: application/json'], $headers);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$code, $resp, $err];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $hdrs),
            'content' => $payload,
            'timeout' => 10,
        ],
    ]);
    $resp = @file_get_contents($url, false, $context);
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $h, $m)) { $code = intval($m[1]); break; }
        }
    }
    return [$code, $resp, ''];
}

// Create a test license key
try {
    $license_key = 'TEST-' . strtoupper(bin2hex(random_bytes(8))) . '-' . time();
} catch (Throwable $e) {
    $license_key = 'TEST-' . strtoupper(bin2hex(openssl_random_pseudo_bytes(8))) . '-' . time();
}

echo "Issuing license token for $license_key\n";
list($code, $body) = postJsonRaw($gateway . '/api/v1/validate', ['license_key' => $license_key]);
echo "validate -> status=$code body=$body\n";
if ($code !== 200) exit(1);
$data = json_decode($body, true) ?: [];
$token = $data['token'] ?? $data['access_token'] ?? null;
if (!$token) { echo "no token returned\n"; exit(1); }

echo "Token: $token\n";

echo "Introspecting token (pre-revoke) -> \n";
list($code, $body, $err) = postJsonRaw($gateway . '/api/v1/introspect', ['token' => $token]);
echo "gateway introspect (pre) status=$code err=$err body=$body\n";

// Read admin token
$admin_token = null;
$admin_token_path = dirname(__DIR__) . '/keys/admin_token.txt';
if (file_exists($admin_token_path)) $admin_token = trim(file_get_contents($admin_token_path));

echo "Revoking license via license-server\n";
if (!empty($admin_token)) {
    list($code, $body, $err) = postJsonRaw($license_server . '/api/v1/revoke', ['license_key' => $license_key], ['Authorization: Bearer ' . $admin_token]);
    echo "license-server revoke status=$code err=$err body=$body\n";
} else {
    echo "No admin token found; cannot revoke\n";
    exit(1);
}

sleep(1);

echo "Introspecting token (post-revoke) via gateway ->\n";
list($code, $body, $err) = postJsonRaw($gateway . '/api/v1/introspect', ['token' => $token]);
echo "gateway introspect (post) status=$code err=$err body=$body\n";

echo "Introspecting token (post-revoke) directly against license-server ->\n";
list($code, $body, $err) = postJsonRaw($license_server . '/api/v1/introspect', ['token' => $token]);
echo "license-server introspect (post) status=$code err=$err body=$body\n";

// Dump blacklist file if present
$bf = __DIR__ . '/../data/jti_blacklist.json';
if (file_exists($bf)) {
    echo "Local blacklist file contents:\n";
    echo file_get_contents($bf) . "\n";
}

exit(0);
