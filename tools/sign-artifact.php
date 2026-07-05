<?php
// Sign a compiled artifact with a PEM private key.
// Usage: php tools/sign-artifact.php --artifact=build/compiled-policy.json --key=private.pem --out=build/compiled-policy.json.sig
$options = getopt('', ['artifact:', 'key:', 'out::']);
$artifact = $options['artifact'] ?? __DIR__ . '/../build/compiled-policy.json';
$keyPath = $options['key'] ?? null;
$out = $options['out'] ?? ($artifact . '.sig');

if (!file_exists($artifact)) { fwrite(STDERR, "ERROR: artifact not found: {$artifact}\n"); exit(1); }
if (!$keyPath || !file_exists($keyPath)) { fwrite(STDERR, "ERROR: private key not found: {$keyPath}\n"); exit(1); }

$content = file_get_contents($artifact);
if ($content === false) { fwrite(STDERR, "ERROR: failed to read artifact\n"); exit(1); }

$privateKey = openssl_pkey_get_private(file_get_contents($keyPath));
if ($privateKey === false) { fwrite(STDERR, "ERROR: failed to parse private key\n"); exit(1); }

$ok = openssl_sign($content, $signature, $privateKey, OPENSSL_ALGO_SHA256);
openssl_free_key($privateKey);
if (!$ok) { fwrite(STDERR, "ERROR: signing failed\n"); exit(1); }

file_put_contents($out, base64_encode($signature));
echo "WROTE: {$out}\n";
exit(0);
