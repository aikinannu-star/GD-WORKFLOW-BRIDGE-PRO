<?php
function base64UrlDecode($input) {
    $remainder = strlen($input) % 4;
    if ($remainder) $input .= str_repeat('=', 4 - $remainder);
    $input = strtr($input, '-_', '+/');
    return base64_decode($input);
}

$token = getenv('TOKEN') ?: '';
$pub = getenv('PUB') ?: '';
if (empty($token) || empty($pub)) {
    echo "TOKEN and PUB environment variables required\n";
    exit(2);
}

list($h,$p,$s) = explode('.', $token);
$signed = $h . '.' . $p;
$signature = base64UrlDecode($s);
$pubKey = file_get_contents($pub);
$ok = openssl_verify($signed, $signature, $pubKey, OPENSSL_ALGO_SHA256);
var_export($ok);
echo PHP_EOL;
