<?php
// Smoke test: issue a license token via the API gateway, confirm introspect, revoke via admin, confirm revoked
$gateway = getenv('GATEWAY_URL') ?: 'http://127.0.0.1:3000';
$license_server = getenv('LICENSE_SERVER_URL') ?: 'http://127.0.0.1:8001';

function postJson($url, $data, $headers = []) {
    $payload = json_encode($data);
    $hdrs = array_merge(['Content-Type: application/json'], $headers);
    // Use curl if available
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, $resp];
    }

    // Fallback to file_get_contents
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
    return [$code, $resp];
}

// Create a test license key (uppercase hex to satisfy validator)
try {
    $license_key = 'TEST-' . strtoupper(bin2hex(random_bytes(8))) . '-' . time();
} catch (Throwable $e) {
    $license_key = 'TEST-' . strtoupper(bin2hex(openssl_random_pseudo_bytes(8))) . '-' . time();
}

echo "Issuing license token for $license_key\n";
list($code, $body) = postJson($gateway . '/api/v1/validate', ['license_key' => $license_key]);
if ($code !== 200) {
    echo "validate failed: status=$code body=$body\n";
    exit(1);
}
$data = json_decode($body, true) ?: [];
$token = $data['token'] ?? $data['access_token'] ?? null;
if (!$token) {
    echo "no token returned: $body\n";
    exit(1);
}

echo "Introspecting token (pre-revoke)\n";
list($code, $body) = postJson($gateway . '/api/v1/introspect', ['token' => $token]);
if ($code !== 200) {
    echo "introspect failed (pre): status=$code body=$body\n";
    exit(1);
}
$j = json_decode($body, true) ?: [];
if (empty($j['success'])) {
    echo "introspect reported failure (pre): $body\n";
    exit(1);
}
echo "Pre-revoke introspect OK\n";

// Read admin token from keys if present
$admin_token = null;
$admin_token_path = dirname(__DIR__) . '/keys/admin_token.txt';
if (file_exists($admin_token_path)) {
    $admin_token = trim(file_get_contents($admin_token_path));
}
// Fallback to admin_secret file
if (empty($admin_token)) {
    $admin_secret_path = dirname(__DIR__) . '/keys/admin_secret.txt';
    if (file_exists($admin_secret_path)) $admin_secret = trim(file_get_contents($admin_secret_path));
    else $admin_secret = null;
} else {
    $admin_secret = null;
}

echo "Revoking license via license-server\n";
if (!empty($admin_token)) {
    list($code, $body) = postJson($license_server . '/api/v1/revoke', ['license_key' => $license_key], ['Authorization: Bearer ' . $admin_token]);
} else {
    list($code, $body) = postJson($license_server . '/api/v1/revoke', ['license_key' => $license_key, 'admin_secret' => $admin_secret]);
}

if ($code !== 200) {
    echo "revoke failed: status=$code body=$body\n";
    exit(1);
}
echo "Revoke accepted\n";

// Wait a moment for Redis propagation
sleep(1);

echo "Introspecting token (post-revoke)\n";
list($code, $body) = postJson($gateway . '/api/v1/introspect', ['token' => $token]);
// Expect a 403 or a success:false indicating revoked_jti
if ($code === 403) {
    echo "Post-revoke introspect returned 403 — treating as revoked. body=$body\n";
    exit(0);
}

$j = json_decode($body, true) ?: [];
if (!empty($j['success']) && $j['success'] === false && !empty($j['message']) && strpos($j['message'], 'revoked') !== false) {
    echo "Post-revoke introspect confirmed revoked: $body\n";
    exit(0);
}

echo "Post-revoke introspect did not indicate revocation (status=$code) body=$body\n";
exit(1);
