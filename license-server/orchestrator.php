<?php
// Simple orchestrator to perform region-sequenced JWKS rotations using the same checks as CI.
// Usage: php orchestrator.php --targets=deploy/targets.json --percentage=20 --region-delay=5

set_time_limit(0);
function dbg($m) { file_put_contents(__DIR__ . '/data/orchestrator.log', date('c') . " " . $m . "\n", FILE_APPEND | LOCK_EX); echo $m . PHP_EOL; }

$options = getopt('', ['targets::','percentage::','region-delay::','dry-run','canary-samples::','canary-success-rate-threshold::','canary-max-latency-ms::']);
$targetsArg = $options['targets'] ?? 'deploy/targets.json';
$percentage = isset($options['percentage']) ? intval($options['percentage']) : 0;
$regionDelay = isset($options['region-delay']) ? intval($options['region-delay']) : intval(getenv('REGION_DELAY_MINUTES') ?: 5);
$dry = isset($options['dry-run']);
$samples = isset($options['canary-samples']) ? intval($options['canary-samples']) : intval(getenv('CANARY_SAMPLES') ?: 5);
$successPct = isset($options['canary-success-rate-threshold']) ? intval($options['canary-success-rate-threshold']) : intval(getenv('CANARY_SUCCESS_RATE_THRESHOLD') ?: 95);
$maxLat = isset($options['canary-max-latency-ms']) ? intval($options['canary-max-latency-ms']) : intval(getenv('CANARY_MAX_LATENCY_MS') ?: 1000);

// Load targets
$targets = [];
if (getenv('ROLLOUT_TARGETS_JSON')) {
    $env = getenv('ROLLOUT_TARGETS_JSON');
    $tmp = json_decode($env, true);
    if (is_array($tmp)) $targets = $tmp;
} elseif (file_exists($targetsArg)) {
    $txt = file_get_contents($targetsArg);
    $tmp = json_decode($txt, true);
    if (is_array($tmp)) $targets = $tmp;
}
if (empty($targets)) { dbg('No targets found'); exit(1); }

// Optional region sequence if provided top-level
$regionSeq = [];
if (is_array($targets) && array_key_exists('region_sequence', $targets)) {
    $regionSeq = $targets['region_sequence'];
}

// Normalize targets array (in case top-level object used)
if (array_key_exists('targets', $targets)) {
    $targets = $targets['targets'];
}

// compute selection by percentage
$total = count($targets);
$selectedCount = $percentage > 0 ? (int)ceil($total * $percentage / 100) : $total;
$selected = array_slice($targets, 0, $selectedCount);

// infer regions if sequence not provided
if (empty($regionSeq)) {
    $seen = [];
    foreach ($selected as $t) {
        $r = $t['region'] ?? 'default';
        if (!isset($seen[$r])) { $regionSeq[] = $r; $seen[$r] = true; }
    }
}

// lock file
$lockFile = __DIR__ . '/data/orchestrator.lock';
$lockTtl = intval(getenv('ORCHESTRATOR_LOCK_TTL') ?: 1200);
if (file_exists($lockFile)) {
    $info = json_decode(file_get_contents($lockFile), true) ?: [];
    $ts = isset($info['ts']) ? strtotime($info['ts']) : 0;
    if ($ts + $lockTtl > time()) { dbg('Another orchestrator run is active'); exit(1); } else { dbg('Stale orchestrator lock found, proceeding'); }
}
file_put_contents($lockFile, json_encode(['pid' => getmypid(), 'ts' => date('c')]), LOCK_EX);

require_once __DIR__ . '/admin_audit.php';
if (file_exists(__DIR__ . '/metrics_lib.php')) require_once __DIR__ . '/metrics_lib.php';

function curl_post($url, $body = '', $headers = []) {
    if (function_exists('curl_version')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $resp];
    }
    $opts = ['http' => ['method' => 'POST','header'=>implode("\r\n", $headers),'content'=>$body,'timeout'=>15]];
    $context = stream_context_create($opts);
    $resp = @file_get_contents($url, false, $context);
    $code = 200;
    return [$code, $resp];
}

function perform_health($target, $samples, $threshold, $maxlat) {
    $url = rtrim($target['url'], '/');
    $license_key = $target['license_test_key'] ?? '';
    if (empty($license_key)) return true;
    $success = 0; $totlat = 0.0;
    for ($i=0;$i<$samples;$i++) {
        $t0 = microtime(true);
        [$c,$resp] = curl_post($url . '/api/v1/validate', http_build_query(['license_key'=>$license_key,'site'=>'ci']), ['Content-Type: application/x-www-form-urlencoded']);
        $t1 = microtime(true);
        $data = json_decode($resp, true) ?: [];
        $token = $data['token'] ?? '';
        if (empty($token)) continue;
        [$c2,$r2] = curl_post($url . '/api/v1/introspect', http_build_query(['token'=>$token]), ['Content-Type: application/x-www-form-urlencoded']);
        $t2 = microtime(true);
        $p = json_decode($r2, true) ?: [];
        if (!empty($p['success'])) $success++;
        $totlat += ($t1-$t0) + ($t2-$t1);
    }
    $rate = $samples>0 ? ($success/$samples)*100 : 100;
    $avg_ms = $success>0 ? ($totlat/$success)*1000 : 999999;
    return ($rate >= $threshold && $avg_ms <= $maxlat);
}

foreach ($regionSeq as $region) {
    dbg("Starting region: $region");
    foreach ($selected as $t) {
        $tregion = $t['region'] ?? 'default';
        if ($tregion !== $region) continue;
        $name = $t['name'] ?? ($t['url'] ?? 'unknown');
        dbg("Rotating $name at {$t['url']}");
        if ($dry) continue;
        $admin_token = $t['admin_token'] ?? getenv('ADMIN_TOKEN') ?: getenv('LICENSE_ADMIN_TOKEN');
        [$code,$rot] = curl_post(rtrim($t['url'], '/') . '/api/v1/jwks/rotate', '', ["Authorization: Bearer $admin_token"]);
        $jr = json_decode($rot, true) ?: [];
        $new = $jr['kid'] ?? null;
        $old = $jr['old_kid'] ?? null;
        if (empty($new)) {
            dbg("Rotation failed for $name: $rot");
            // attempt rollback
            if (!empty($old)) {
                curl_post(rtrim($t['url'], '/') . '/api/v1/jwks/activate', json_encode(['kid'=>$old]), ["Authorization: Bearer $admin_token","Content-Type: application/json"]);
            } else {
                curl_post(rtrim($t['url'], '/') . '/api/v1/jwks/rollback', '', ["Authorization: Bearer $admin_token"]);
            }
            audit_log_admin('orchestrator_failed', ['target'=>$name, 'response'=>$rot]);
            unlink($lockFile);
            exit(2);
        }
        audit_log_admin('orchestrator_rotate', ['target'=>$name,'new_kid'=>$new,'old_kid'=>$old]);
        // health check
        $ok = perform_health($t, $samples, $successPct, $maxLat);
        if (!$ok) {
            dbg("Health check failed for $name, attempting rollback");
            if (!empty($old)) {
                curl_post(rtrim($t['url'], '/') . '/api/v1/jwks/activate', json_encode(['kid'=>$old]), ["Authorization: Bearer $admin_token","Content-Type: application/json"]);
            } else {
                curl_post(rtrim($t['url'], '/') . '/api/v1/jwks/rollback', '', ["Authorization: Bearer $admin_token"]);
            }
            audit_log_admin('orchestrator_health_failed', ['target'=>$name]);
            unlink($lockFile);
            exit(3);
        }
    }
    dbg("Completed region: $region, waiting $regionDelay minutes");
    sleep($regionDelay * 60);
}

unlink($lockFile);
dbg('Orchestration completed');
exit(0);
