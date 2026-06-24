<?php
// Child runner for testing ControlPlaneAuth::enforceOrExit behaviour.
require_once __DIR__ . '/../../services/lib/ServiceHelpers.php';
require_once __DIR__ . '/../../services/lib/ControlPlaneAuth.php';

// Ensure env is set for the control-plane token
$_ENV['GATEWAY_HEALTH_TOKEN'] = 's3cr3t';

$mode = $argv[1] ?? 'no-header';
if ($mode === 'with-header') {
    // Provide header via $_SERVER as the gateway would
    $_SERVER['HTTP_X_HEALTH_TOKEN'] = 's3cr3t';
}

ControlPlaneAuth::enforceOrExit();

// If enforcement passed, continue and signal OK
echo "OK\n";
exit(0);
