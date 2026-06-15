<?php
// CLI helper to create or rotate a client secret (development).
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

$argc = $_SERVER['argc'];
$argv = $_SERVER['argv'];

if ($argc < 2) {
    echo "Usage: php generate_client.php <client_id> [secret]\n";
    echo "If secret is omitted a strong random secret is generated and printed once.\n";
    exit(1);
}

$clientId = $argv[1];
$secret = $argc >= 3 ? $argv[2] : null;
if (empty($secret)) {
    // generate a 32-char URL-safe secret
    $secret = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

$hash = password_hash($secret, PASSWORD_DEFAULT);

$clientsFile = __DIR__ . '/keys/clients.json';
$clients = [];
if (file_exists($clientsFile)) {
    $cjson = json_decode(file_get_contents($clientsFile), true);
    if (is_array($cjson)) $clients = $cjson;
}

$clients[$clientId] = $clients[$clientId] ?? ['name' => $clientId, 'scopes' => []];
$clients[$clientId]['client_secret_hash'] = $hash;

if (!is_dir(dirname($clientsFile))) @mkdir(dirname($clientsFile), 0755, true);
file_put_contents($clientsFile, json_encode($clients, JSON_PRETTY_PRINT));

echo "Wrote client '$clientId' with hashed secret to keys/clients.json\n";
echo "Secret (store securely, shown once): $secret\n";
exit(0);
