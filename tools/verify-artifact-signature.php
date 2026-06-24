<?php
// Verify compiled artifact signature with a PEM public key.
// Usage: php tools/verify-artifact-signature.php --artifact=build/compiled-policy.json --sig=build/compiled-policy.json.sig --pub=public.pem
$options = getopt('', ['artifact:', 'sig:', 'pub:']);
$artifact = $options['artifact'] ?? __DIR__ . '/../build/compiled-policy.json';
$sigPath = $options['sig'] ?? ($artifact . '.sig');
$pubPath = $options['pub'] ?? null;

if (!file_exists($artifact) || !file_exists($sigPath) || !$pubPath || !file_exists($pubPath)) {
    fwrite(STDERR, "ERROR: missing artifact/sig/pub files.\n");
    exit(1);
}

$content = file_get_contents($artifact);
$sig = base64_decode(file_get_contents($sigPath));
$pub = openssl_pkey_get_public(file_get_contents($pubPath));
if ($pub === false) { fwrite(STDERR, "ERROR: failed to parse public key\n"); exit(1); }
$ok = openssl_verify($content, $sig, $pub, OPENSSL_ALGO_SHA256);
openssl_free_key($pub);
if ($ok === 1) {
    echo "PASS: signature valid\n";
    exit(0);
}
if ($ok === 0) {
    fwrite(STDERR, "FAIL: signature invalid\n");
    exit(2);
}
fwrite(STDERR, "ERROR: signature verification error\n");
exit(3);
