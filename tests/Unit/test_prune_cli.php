<?php
require_once __DIR__ . '/../../license-server/jwks_lib.php';

$tmp = sys_get_temp_dir() . '/jwks_test_' . uniqid();
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) { fwrite(STDERR, "mkdir failed\n"); exit(2); }
global $keysDir, $keysIndexFile;
$keysDir = $tmp;
$keysIndexFile = $keysDir . '/keys_index.json';

$old_kid = 'kid_old_' . uniqid();
$pub = $keysDir . '/public_' . $old_kid . '.pem';
$priv = $keysDir . '/private_' . $old_kid . '.pem';
file_put_contents($pub, "PUBLIC");
file_put_contents($priv, "PRIVATE");

$index = [
    'current_kid' => null,
    'keys' => [$old_kid => ['kid' => $old_kid, 'alg' => 'RS256']],
    'keys_meta' => [$old_kid => ['created_at' => date('c', time()-3600), 'retire_at' => date('c', time()-10)]],
    'rotation_history' => []
];
saveKeysIndex($index);

$loaded = getKeysIndex();
if (!isset($loaded['keys'][$old_kid])) { fwrite(STDERR, "FAIL: index missing old kid\n"); exit(3); }

$removed = pruneExpiredKeys($loaded);
if (!in_array($old_kid, $removed)) { fwrite(STDERR, "FAIL: expected $old_kid to be pruned\n"); exit(4); }
if (file_exists($pub) || file_exists($priv)) { fwrite(STDERR, "FAIL: old files still exist\n"); exit(5); }

echo "PASS prune_cli\n";
exit(0);
