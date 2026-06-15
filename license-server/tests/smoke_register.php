<?php
// Simple smoke test: register a user via the API gateway and call /auth/me
$api = 'http://127.0.0.1:3000/api/v1';
$email = 'ci+' . time() . '@example.com';
$password = 'Password123!';

function postJson($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $resp];
}

list($code, $body) = postJson($api . '/auth/register', ['email' => $email, 'password' => $password]);
if ($code !== 201) {
    echo "register failed: status=$code body=$body\n";
    exit(1);
}
$data = json_decode($body, true);
$token = $data['data']['access_token'] ?? $data['access_token'] ?? null;
if (!$token) {
    echo "no access token returned: $body\n";
    exit(1);
}

$ch = curl_init($api . '/auth/me');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
if ($code !== 200) {
    echo "userinfo failed: status=$code body=$resp\n";
    exit(1);
}

echo "PHP smoke test passed (registered $email)\n";
exit(0);
