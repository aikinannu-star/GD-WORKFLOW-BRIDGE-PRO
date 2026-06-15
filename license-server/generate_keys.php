<?php
// Generate RSA keypair for local license server (development only)
echo "php_ini_loaded_file: " . (php_ini_loaded_file() ?: 'none') . PHP_EOL;
echo "OpenSSL loaded: " . (extension_loaded('openssl') ? 'yes' : 'no') . PHP_EOL;
if (!extension_loaded('openssl')) {
    echo "OpenSSL extension not available.\n";
    exit(1);
}
if (!defined('OPENSSL_KEYTYPE_RSA')) {
    echo "OPENSSL_KEYTYPE_RSA constant not defined.\n";
    exit(1);
}

$attempts = [];
$cfgs = [
    ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
];

$generated = false;
foreach ($cfgs as $cfg) {
    $res = @openssl_pkey_new($cfg);
    if ($res !== false) {
        $generated = $res;
        break;
    }
    while ($err = openssl_error_string()) {
        $attempts[] = $err;
    }
}

// Try some likely openssl.cnf locations if initial attempt failed
if (!$generated) {
    $candidates = [
        __DIR__ . '/keys',
        __DIR__ . '/../',
        getenv('OPENSSL_CONF') ?: '',
    ];
    foreach ($candidates as $cand) {
        if (empty($cand)) continue;
        $conf = rtrim($cand, "\\/") . '/openssl.cnf';
        if (!file_exists($conf)) continue;
        $cfg = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $conf];
        $res = @openssl_pkey_new($cfg);
        if ($res !== false) {
            $generated = $res;
            break;
        }
        while ($err = openssl_error_string()) {
            $attempts[] = $err;
        }
    }
}

if (!$generated) {
    echo "failed to create key\n";
    if (!empty($attempts)) {
        echo "OpenSSL errors:\n" . implode("\n", $attempts) . "\n";
    }
    exit(1);
}

openssl_pkey_export($generated, $priv);
$pub_details = openssl_pkey_get_details($generated);
$pub = $pub_details['key'] ?? '';
if (empty($priv) || empty($pub)) {
    echo "key export failed\n";
    exit(1);
}
// Determine output paths (allow overrides for production deployments)
$privateOut = getenv('LICENSE_PRIVATE_KEY_PATH') ?: __DIR__ . '/keys/private.pem';
$publicOut = getenv('LICENSE_PUBLIC_KEY_PATH') ?: __DIR__ . '/keys/public.pem';
$adminSecretOut = getenv('LICENSE_ADMIN_SECRET_PATH') ?: __DIR__ . '/keys/admin_secret.txt';

// In production, avoid writing private keys inside the repo
$licenseEnv = getenv('LICENSE_SERVER_ENV') ?: 'dev';
if (strtolower($licenseEnv) === 'production') {
    $keyReal = realpath(dirname($privateOut));
    $repoReal = realpath(__DIR__);
    if ($keyReal !== false && $repoReal !== false && strpos($keyReal, $repoReal) === 0) {
        echo "Refusing to write private key into repository in production. Set LICENSE_PRIVATE_KEY_PATH to an external secure path.\n";
        exit(1);
    }
}

// Ensure directories exist for outputs
if (!is_dir(dirname($privateOut))) {
    @mkdir(dirname($privateOut), 0755, true);
}
if (!is_dir(dirname($publicOut))) {
    @mkdir(dirname($publicOut), 0755, true);
}

file_put_contents($privateOut, $priv);
file_put_contents($publicOut, $pub);
file_put_contents($adminSecretOut, 'dev_admin_secret_demo_20260521_7gHk');

echo "generated keys:\n  private: $privateOut\n  public:  $publicOut\n";
