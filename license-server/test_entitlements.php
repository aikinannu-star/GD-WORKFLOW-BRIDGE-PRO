<?php
require 'entitlements.php';

echo "=== Entitlements Helper Test ===\n\n";

// Test 1: List plans
echo "1. List Plans:\n";
$plans = list_plans();
foreach ($plans as $name => $plan) {
    echo "  - $name: tier={$plan['tier']}, features=" . count($plan['features']) . ", support={$plan['support']}\n";
}

// Test 2: Check feature availability
echo "\n2. Feature Availability:\n";
echo "  - free has 'basic_sync': " . (has_feature('free', 'basic_sync') ? 'YES' : 'NO') . "\n";
echo "  - free has 'webhooks': " . (has_feature('free', 'webhooks') ? 'YES' : 'NO') . "\n";
echo "  - pro has 'webhooks': " . (has_feature('pro', 'webhooks') ? 'YES' : 'NO') . "\n";
echo "  - enterprise has 'sso': " . (has_feature('enterprise', 'sso') ? 'YES' : 'NO') . "\n";

// Test 3: Get plan limits
echo "\n3. Plan Limits:\n";
echo "  - free projects: " . (get_plan_limit('free', 'projects') ?? 'unlimited') . "\n";
echo "  - pro api_calls_per_day: " . (get_plan_limit('pro', 'api_calls_per_day') ?? 'unlimited') . "\n";
echo "  - enterprise storage_gb: " . (get_plan_limit('enterprise', 'storage_gb') ?? 'unlimited') . "\n";

// Test 4: Enforce entitlements
echo "\n4. Enforce Entitlements:\n";
$result = enforce_entitlement('pro', 'webhooks');
echo "  - pro with webhooks: " . ($result['success'] ? 'OK' : 'DENIED') . "\n";

$result = enforce_entitlement('free', 'sso');
echo "  - free with sso: " . ($result['success'] ? 'OK' : 'DENIED - ' . $result['detail']) . "\n";

// Test 5: JWT entitlement payload
echo "\n5. JWT Entitlement Payload:\n";
$payload = get_entitlement_payload('pro');
echo "  - pro payload: plan={$payload['plan']}, tier={$payload['tier']}, features=" . count($payload['features']) . "\n";

// Test 6: Plan comparison
echo "\n6. Plan Tiers:\n";
echo "  - compare free vs pro: " . compare_plans('free', 'pro') . " (should be -1)\n";
echo "  - compare pro vs enterprise: " . compare_plans('pro', 'enterprise') . " (should be -1)\n";
echo "  - compare free vs free: " . compare_plans('free', 'free') . " (should be 0)\n";

echo "\n=== All Tests Complete ===\n";
