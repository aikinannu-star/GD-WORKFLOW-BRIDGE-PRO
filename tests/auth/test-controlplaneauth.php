<?php
require_once __DIR__ . '/../../services/lib/ServiceHelpers.php';
require_once __DIR__ . '/../../services/lib/ControlPlaneAuth.php';

function fail(string $msg): void {
    echo "FAIL: $msg\n";
    exit(1);
}

function ok(string $msg): void {
    echo "PASS: $msg\n";
}

// Test extractProvidedToken via X-Health-Token header
$_SERVER['HTTP_X_HEALTH_TOKEN'] = 'token-x';
$t = ControlPlaneAuth::extractProvidedToken();
if ($t !== 'token-x') {
    fail('extractProvidedToken should read X-Health-Token');
}
ok('extractProvidedToken reads X-Health-Token');

// Test extractProvidedToken via Authorization Bearer
unset($_SERVER['HTTP_X_HEALTH_TOKEN']);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer bearer-token';
$t = ControlPlaneAuth::extractProvidedToken();
if ($t !== 'bearer-token') {
    fail('extractProvidedToken should read Authorization Bearer token');
}
ok('extractProvidedToken reads Authorization Bearer');

// Test isEnabled/getRequiredToken
$_ENV['GATEWAY_HEALTH_TOKEN'] = 's3cr3t';
if (!ControlPlaneAuth::isEnabled()) {
    fail('isEnabled should be true when env set');
}
if (ControlPlaneAuth::getRequiredToken() !== 's3cr3t') {
    fail('getRequiredToken should return env value');
}
ok('isEnabled/getRequiredToken');

// Test enforceOrExit behaviour via subprocesses
$runner = __DIR__ . '/_runner_controlplane_enforce.php';

// 1) Negative case: no header provided -> should output unauthorized JSON
$cmd = 'php ' . escapeshellarg($runner) . ' ' . escapeshellarg('no-header');
exec($cmd, $out, $ret);
$outstr = implode("\n", $out);
if (stripos($outstr, 'unauthorized') === false) {
    fail('enforceOrExit without header should output unauthorized JSON');
}
ok('enforceOrExit denies when no header');

// 2) Positive case: provide header -> child prints OK
$cmd = 'php ' . escapeshellarg($runner) . ' ' . escapeshellarg('with-header');
exec($cmd, $out, $ret);
$outstr = implode("\n", $out);
if (stripos($outstr, 'OK') === false || $ret !== 0) {
    fail('enforceOrExit with correct header should allow and child should continue');
}
ok('enforceOrExit allows when correct header');

echo "All ControlPlaneAuth tests passed\n";
exit(0);
