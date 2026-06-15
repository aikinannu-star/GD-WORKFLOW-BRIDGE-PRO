<?php
// Entitlements helper for plan-based feature enforcement
// Provides functions to check features, limits, and entitlement tier

/**
 * Load plan definitions from plans.json
 */
function load_plans(): array {
    $plansFile = __DIR__ . '/data/plans.json';
    if (!file_exists($plansFile)) {
        return [];
    }
    $plans = json_decode(file_get_contents($plansFile), true);
    return is_array($plans) ? $plans : [];
}

/**
 * Check if a feature is available for a given plan
 */
function has_feature(string $plan, string $feature): bool {
    $plans = load_plans();
    if (empty($plans[$plan])) {
        return false;
    }
    $features = $plans[$plan]['features'] ?? [];
    return in_array($feature, $features, true);
}

/**
 * Get all features for a plan
 */
function get_plan_features(string $plan): array {
    $plans = load_plans();
    if (empty($plans[$plan])) {
        return [];
    }
    return $plans[$plan]['features'] ?? [];
}

/**
 * Get plan tier (1=free, 2=pro, 3=enterprise)
 */
function get_plan_tier(string $plan): int {
    $plans = load_plans();
    if (empty($plans[$plan])) {
        return 0;
    }
    return $plans[$plan]['tier'] ?? 0;
}

/**
 * Get limit for a plan
 * Returns null if unlimited
 */
function get_plan_limit(string $plan, string $limit_name): ?int {
    $plans = load_plans();
    if (empty($plans[$plan])) {
        return null;
    }
    $limits = $plans[$plan]['limits'] ?? [];
    return $limits[$limit_name] ?? null;
}

/**
 * Enforce limit check
 * Returns true if under limit, false if exceeded
 * Returns true if limit is null (unlimited)
 */
function check_limit(string $plan, string $limit_name, int $current_usage): bool {
    $limit = get_plan_limit($plan, $limit_name);
    if ($limit === null) {
        return true; // unlimited
    }
    return $current_usage < $limit;
}

/**
 * Get support level for a plan
 */
function get_plan_support(string $plan): string {
    $plans = load_plans();
    if (empty($plans[$plan])) {
        return 'none';
    }
    return $plans[$plan]['support'] ?? 'none';
}

/**
 * Get all plan details for a plan
 */
function get_plan_details(string $plan): ?array {
    $plans = load_plans();
    if (empty($plans[$plan])) {
        return null;
    }
    return $plans[$plan];
}

/**
 * Enforce entitlement check
 * Returns array with success, message, and (optionally) enforced_features
 */
function enforce_entitlement(string $plan, ?string $feature_name = null): array {
    $plans = load_plans();
    
    if (empty($plans[$plan])) {
        return [
            'success' => false,
            'message' => 'invalid_plan',
            'detail' => 'Plan not found: ' . $plan,
        ];
    }
    
    // If checking a specific feature
    if ($feature_name !== null) {
        if (!has_feature($plan, $feature_name)) {
            return [
                'success' => false,
                'message' => 'feature_not_available',
                'detail' => 'Feature ' . $feature_name . ' not available for plan ' . $plan,
            ];
        }
    }
    
    return [
        'success' => true,
        'message' => 'plan_valid',
        'plan' => $plan,
        'tier' => get_plan_tier($plan),
        'features' => get_plan_features($plan),
    ];
}

/**
 * Get JWT payload entitlements for a plan
 * Used when issuing tokens
 */
function get_entitlement_payload(string $plan): array {
    return [
        'plan' => $plan,
        'tier' => get_plan_tier($plan),
        'features' => get_plan_features($plan),
    ];
}

/**
 * Validate entitlements from JWT payload
 */
function validate_entitlement_payload(array $payload): array {
    if (empty($payload['plan'])) {
        return [
            'success' => false,
            'message' => 'no_plan_in_token',
        ];
    }
    
    $plan = $payload['plan'];
    $declared_features = $payload['features'] ?? [];
    
    // Get actual features for this plan
    $actual_features = get_plan_features($plan);
    
    // Check if declared features match actual plan features
    $feature_mismatch = array_diff($declared_features, $actual_features);
    if (!empty($feature_mismatch)) {
        return [
            'success' => false,
            'message' => 'feature_mismatch',
            'detail' => 'Token claims features not available in plan: ' . implode(', ', $feature_mismatch),
        ];
    }
    
    return [
        'success' => true,
        'message' => 'entitlements_valid',
        'plan' => $plan,
        'tier' => get_plan_tier($plan),
        'features' => $actual_features,
    ];
}

/**
 * List all available plans
 */
function list_plans(): array {
    return load_plans();
}

/**
 * Compare plans by tier
 * Returns: -1 if plan1 < plan2, 0 if equal, 1 if plan1 > plan2
 */
function compare_plans(string $plan1, string $plan2): int {
    $tier1 = get_plan_tier($plan1);
    $tier2 = get_plan_tier($plan2);
    
    if ($tier1 < $tier2) return -1;
    if ($tier1 > $tier2) return 1;
    return 0;
}
