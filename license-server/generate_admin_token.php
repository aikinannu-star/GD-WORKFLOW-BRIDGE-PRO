<?php
// CLI helper to generate an admin token and optionally credentials.
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$keysDir = __DIR__ . '/keys';
if (!is_dir($keysDir)) mkdir($keysDir, 0755, true);

$argc = $_SERVER['argc'];
$argv = $_SERVER['argv'];

// If username and password provided, create credentials file
if ($argc >= 3) {
    $username = $argv[1];
    $password = $argv[2];
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $cred = ['username' => $username, 'password_hash' => $hash];
    file_put_contents($keysDir . '/admin_credentials.json', json_encode($cred, JSON_PRETTY_PRINT));
    echo "Wrote admin credentials to keys/admin_credentials.json (username={$username}).\n";
}

// Always generate or rotate token
$token = base64url_encode(random_bytes(32));
file_put_contents($keysDir . '/admin_token.txt', $token);
echo "Admin token written to keys/admin_token.txt\n";
echo "Token: {$token}\n";

exit(0);
