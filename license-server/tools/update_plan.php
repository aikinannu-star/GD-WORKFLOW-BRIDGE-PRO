<?php
// Simple helper to update a license plan in the DB for local testing
require_once __DIR__ . '/..\db.php';

$license_key = $argv[1] ?? '';
$plan = $argv[2] ?? '';
if (empty($license_key) || empty($plan)) {
    echo "usage: php update_plan.php <license_key> <plan>\n";
    exit(2);
}

$pdo = get_db_connection();
if (!$pdo) {
    echo "DB connection failed\n";
    exit(1);
}

$plansFile = __DIR__ . '/../data/plans.json';
$plans = [];
if (file_exists($plansFile)) {
    $plans = json_decode(file_get_contents($plansFile), true) ?: [];
}
$features = ['files_vault','analytics','webhooks'];
if (isset($plans[$plan]) && isset($plans[$plan]['features']) && is_array($plans[$plan]['features'])) {
    $features = $plans[$plan]['features'];
}
$exp = time() + 365 * 24 * 3600;
$res = db_update_license_after_activation($pdo, $license_key, $exp, $features, $plan);
if ($res) {
    echo "updated {$license_key} to plan {$plan}\n";
    exit(0);
}

echo "update failed\n";
exit(1);
