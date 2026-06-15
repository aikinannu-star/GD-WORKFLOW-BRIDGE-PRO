<?php
function base64UrlDecode($input) {
    $remainder = strlen($input) % 4;
    if ($remainder) $input .= str_repeat('=', 4 - $remainder);
    $input = strtr($input, '-_', '+/');
    return base64_decode($input);
}

$token = getenv('TOKEN') ?: '';
if (empty($token)) { echo "TOKEN env required\n"; exit(2); }

list($h,$p,$s) = explode('.', $token);
$signed = $h . '.' . $p;
$sig = base64UrlDecode($s);

echo "Inspecting token...\n";
// show header
echo "Header: " . base64_decode(strtr($h, '-_', '+/')) . "\n";

$keysDir = __DIR__ . '/../license-server/keys';
if (!is_dir($keysDir)) { echo "No keys dir\n"; exit(1); }

$indexFile = $keysDir . '/keys_index.json';
if (file_exists($indexFile)) {
    $idx = json_decode(file_get_contents($indexFile), true);
    foreach (array_keys($idx['keys'] ?? []) as $kid) {
        $pub = $keysDir . '/public_' . $kid . '.pem';
        if (!file_exists($pub)) { echo "missing $pub\n"; continue; }
        $pubKey = file_get_contents($pub);
        $ok = openssl_verify($signed, $sig, $pubKey, OPENSSL_ALGO_SHA256);
        echo "kid=$kid -> verify= " . var_export($ok, true) . "\n";
    }
} else {
    echo "No keys_index.json\n";
}

// try canonical
$pub = $keysDir . '/public.pem';
if (file_exists($pub)) {
    $pubKey = file_get_contents($pub);
    $ok = openssl_verify($signed, $sig, $pubKey, OPENSSL_ALGO_SHA256);
    echo "canonical public.pem -> " . var_export($ok, true) . "\n";
}
