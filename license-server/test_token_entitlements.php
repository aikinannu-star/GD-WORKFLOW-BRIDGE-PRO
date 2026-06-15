<?php
require 'entitlements.php';

echo "=== Token Issuance with Entitlements Test ===\n\n";

// Create a test license payload similar to what server.php would create
$plans = list_plans();
$test_cases = [
    ['license_key' => 'FREE-TEST-12345678901234567890', 'plan' => 'free'],
    ['license_key' => 'PRO-TEST-123456789012345678901', 'plan' => 'pro'],
    ['license_key' => 'ENT-TEST-12345678901234567890', 'plan' => 'enterprise'],
];

foreach ($test_cases as $case) {
    $plan = $case['plan'];
    $license_key = $case['license_key'];
    
    echo "License: $license_key | Plan: $plan\n";
    
    // Simulate token payload construction
    $payload = [
        'iss' => 'gdwb-license-server',
        'sub' => $license_key,
        'aud' => 'gd-workflow-bridge-pro',
        'iat' => time(),
        'exp' => time() + 86400,
        'jti' => bin2hex(random_bytes(8)),
        'plan' => $plan,
        'tier' => get_plan_tier($plan),
        'features' => get_plan_features($plan),
        'site' => 'https://example.com'
    ];
    
    echo "  Plan: {$payload['plan']}\n";
    echo "  Tier: {$payload['tier']}\n";
    echo "  Features: " . implode(', ', array_slice($payload['features'], 0, 3)) . " (+more)\n";
    echo "  Feature count: " . count($payload['features']) . "\n";
    
    // Validate entitlements
    $validation = validate_entitlement_payload($payload);
    echo "  Validation: " . ($validation['success'] ? 'OK' : 'FAILED - ' . $validation['message']) . "\n";
    echo "\n";
}

// Test limit enforcement
echo "=== Limit Enforcement ===\n\n";
$limits_test = [
    ['plan' => 'free', 'limit' => 'projects', 'usage' => 3],
    ['plan' => 'free', 'limit' => 'projects', 'usage' => 6],  // Should fail
    ['plan' => 'pro', 'limit' => 'api_calls_per_day', 'usage' => 50000],
    ['plan' => 'pro', 'limit' => 'api_calls_per_day', 'usage' => 150000],  // Should fail
    ['plan' => 'enterprise', 'limit' => 'projects', 'usage' => 1000],  // Should pass (unlimited)
];

foreach ($limits_test as $test) {
    $plan = $test['plan'];
    $limit = $test['limit'];
    $usage = $test['usage'];
    $allowed = check_limit($plan, $limit, $usage);
    $max = get_plan_limit($plan, $limit);
    
    $max_str = $max === null ? 'unlimited' : $max;
    $status = $allowed ? '✓ OK' : '✗ EXCEEDED';
    echo "$plan: $limit usage=$usage (max=$max_str) $status\n";
}

echo "\n=== All Tests Complete ===\n";
