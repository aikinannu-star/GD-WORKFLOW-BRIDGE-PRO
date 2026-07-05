<?php
/**
 * Risk Zone Thresholds Configuration
 * 
 * Central source for risk zone definitions used by:
 * - Health vs Volatility Matrix UI
 * - Time Series Analytics Engine
 * - Drift Analysis Engine
 * - Operations Center Dashboard
 * 
 * This prevents threshold drift between components and ensures
 * consistent risk classification across the platform.
 * 
 * @package GD\Workflow\Bridge\Config
 */

namespace GD\Workflow\Config;

/**
 * Risk Zone Definitions
 * 
 * Each zone is defined by:
 * - health_min: Minimum health score (0-100)
 * - health_max: Maximum health score (0-100)
 * - volatility_min: Minimum volatility (0-100)
 * - volatility_max: Maximum volatility (0-100)
 * - color: Display color (hex)
 * - icon: Risk indicator symbol
 * - description: Human-readable description
 * - remediation_priority: Action urgency (1=highest)
 */
const RISK_ZONES = [
    'healthy' => [
        'id' => 'healthy',
        'name' => 'Healthy',
        'health_min' => 75,
        'health_max' => 100,
        'volatility_min' => 0,
        'volatility_max' => 30,
        'color' => '#10b981',           // Green
        'light_color' => '#d1fae5',
        'icon' => '🟢',
        'description' => 'High health, stable performance',
        'remediation_priority' => 4,
        'status' => 'monitor',
        'action' => 'Continue monitoring'
    ],
    
    'watch' => [
        'id' => 'watch',
        'name' => 'Watch',
        'health_min' => 75,
        'health_max' => 100,
        'volatility_min' => 30,
        'volatility_max' => 100,
        'color' => '#f59e0b',           // Amber
        'light_color' => '#fef3c7',
        'icon' => '🟠',
        'description' => 'Good health, but increasing volatility',
        'remediation_priority' => 3,
        'status' => 'observe',
        'action' => 'Stabilize and investigate volatility source'
    ],
    
    'stagnant' => [
        'id' => 'stagnant',
        'name' => 'Stagnant',
        'health_min' => 50,
        'health_max' => 75,
        'volatility_min' => 0,
        'volatility_max' => 100,
        'color' => '#6366f1',           // Indigo
        'light_color' => '#e0e7ff',
        'icon' => '🔵',
        'description' => 'Moderate health, optimization needed',
        'remediation_priority' => 2,
        'status' => 'improve',
        'action' => 'Review performance metrics and optimize'
    ],
    
    'critical' => [
        'id' => 'critical',
        'name' => 'Critical',
        'health_min' => 0,
        'health_max' => 50,
        'volatility_min' => 0,
        'volatility_max' => 30,
        'color' => '#ef4444',           // Red
        'light_color' => '#fee2e2',
        'icon' => '🔴',
        'description' => 'Low health, but stable degradation',
        'remediation_priority' => 1,
        'status' => 'critical',
        'action' => 'Investigate root cause immediately'
    ],
    
    'degrading' => [
        'id' => 'degrading',
        'name' => 'Degrading',
        'health_min' => 0,
        'health_max' => 50,
        'volatility_min' => 30,
        'volatility_max' => 100,
        'color' => '#dc2626',           // Dark Red
        'light_color' => '#fecaca',
        'icon' => '🟥',
        'description' => 'Critical: Severe health decline and volatility',
        'remediation_priority' => 1,
        'status' => 'emergency',
        'action' => 'Emergency response required - escalate immediately'
    ]
];

/**
 * Health Score Thresholds
 * Used for single-metric health classification
 */
const HEALTH_THRESHOLDS = [
    'healthy' => 75,        // >= 75%
    'stagnant' => 50,       // 50-75%
    'at_risk' => 0,         // < 50%
];

/**
 * Volatility Thresholds
 * Used for stability classification
 */
const VOLATILITY_THRESHOLDS = [
    'stable' => 30,         // <= 30%
    'volatile' => 100,      // > 30%
];

/**
 * Determine risk zone for a given tenant
 * 
 * @param float $health_score Health score (0-100)
 * @param float $volatility Volatility score (0-100)
 * @return array Risk zone configuration
 */
function normalizeFractionalScore(float $value): float
{
    $normalized = max(0.0, min(100.0, $value));
    if ($normalized > 0.0 && $normalized <= 1.0) {
        return $normalized * 100.0;
    }
    return $normalized;
}

function getRiskZone(float $health_score, float $volatility): array
{
    $health = normalizeFractionalScore($health_score);
    $volatility = normalizeFractionalScore($volatility);
    
    // Determine zone
    foreach (RISK_ZONES as $zone_id => $zone) {
        if ($health >= $zone['health_min'] && $health <= $zone['health_max'] &&
            $volatility >= $zone['volatility_min'] && $volatility <= $zone['volatility_max']) {
            return $zone;
        }
    }
    
    // Fallback (shouldn't reach here with proper normalization)
    return RISK_ZONES['critical'];
}

/**
 * Get risk zone by ID
 * 
 * @param string $zone_id Risk zone identifier
 * @return array|null Risk zone configuration or null if not found
 */
function getRiskZoneById(string $zone_id): ?array
{
    return RISK_ZONES[$zone_id] ?? null;
}

/**
 * Get all zone definitions
 * 
 * @return array All risk zones
 */
function getAllRiskZones(): array
{
    return RISK_ZONES;
}

/**
 * Get zone color by ID
 * 
 * @param string $zone_id Risk zone identifier
 * @return string Hex color code
 */
function getZoneColor(string $zone_id): string
{
    return RISK_ZONES[$zone_id]['color'] ?? '#cccccc';
}

/**
 * Get zone for health score only
 * 
 * @param float $health_score Health score (0-100)
 * @return string 'healthy'|'stagnant'|'at_risk'
 */
function getHealthStatus(float $health_score): string
{
    $health = max(0, min(100, $health_score));
    
    if ($health >= HEALTH_THRESHOLDS['healthy']) {
        return 'healthy';
    } elseif ($health >= HEALTH_THRESHOLDS['stagnant']) {
        return 'stagnant';
    } else {
        return 'at_risk';
    }
}

/**
 * Get zone for volatility only
 * 
 * @param float $volatility Volatility score (0-100)
 * @return string 'stable'|'volatile'
 */
function getVolatilityStatus(float $volatility): string
{
    $vol = max(0, min(100, $volatility));
    
    return $vol <= VOLATILITY_THRESHOLDS['stable'] ? 'stable' : 'volatile';
}

/**
 * Calculate remediation priority from zone
 * 
 * @param string $zone_id Risk zone identifier
 * @return int Priority (1=highest/most urgent, 4=lowest/monitor only)
 */
function getRemediationPriority(string $zone_id): int
{
    return RISK_ZONES[$zone_id]['remediation_priority'] ?? 2;
}

/**
 * Format zone info for UI consumption
 * 
 * @param float $health Health score
 * @param float $volatility Volatility score
 * @return array Formatted zone information with all UI properties
 */
function formatZoneForUI(float $health, float $volatility): array
{
    $health = normalizeFractionalScore($health);
    $volatility = normalizeFractionalScore($volatility);
    $zone = getRiskZone($health, $volatility);
    
    return [
        'zone_id' => $zone['id'],
        'zone_name' => $zone['name'],
        'zone_icon' => $zone['icon'],
        'zone_color' => $zone['color'],
        'zone_light_color' => $zone['light_color'],
        'zone_description' => $zone['description'],
        'health_score' => round($health, 1),
        'volatility_score' => round($volatility, 1),
        'status' => $zone['status'],
        'recommended_action' => $zone['action'],
        'remediation_priority' => $zone['remediation_priority']
    ];
}

/**
 * Validate risk thresholds are internally consistent
 * Used for health checks
 * 
 * @return bool True if thresholds are valid
 */
function validateRiskThresholds(): bool
{
    foreach (RISK_ZONES as $zone) {
        // Each zone must have valid ranges
        if ($zone['health_min'] > $zone['health_max'] ||
            $zone['volatility_min'] > $zone['volatility_max']) {
            return false;
        }
        
        // Health and volatility should be 0-100
        if ($zone['health_min'] < 0 || $zone['health_max'] > 100 ||
            $zone['volatility_min'] < 0 || $zone['volatility_max'] > 100) {
            return false;
        }
    }
    
    return true;
}

/**
 * Export zone configuration as JSON
 * Used for frontend consumption
 * 
 * @return string JSON-encoded risk zones
 */
function exportRiskZonesAsJSON(): string
{
    $zones = [];
    
    foreach (RISK_ZONES as $zone_id => $zone) {
        $zones[$zone_id] = [
            'id' => $zone['id'],
            'name' => $zone['name'],
            'health_min' => $zone['health_min'],
            'health_max' => $zone['health_max'],
            'volatility_min' => $zone['volatility_min'],
            'volatility_max' => $zone['volatility_max'],
            'color' => $zone['color'],
            'icon' => $zone['icon'],
            'description' => $zone['description']
        ];
    }
    
    return json_encode($zones, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

// ============================================
// Namespace-Level Assertions
// ============================================

if (!validateRiskThresholds()) {
    throw new \RuntimeException('Risk zone threshold configuration is invalid. Check Config/RiskZones.php');
}

// Export functions to global scope if needed (optional)
// This allows backward compatibility with non-namespaced code
if (!function_exists('get_risk_zone')) {
    function get_risk_zone(float $health, float $volatility): array
    {
        return getRiskZone($health, $volatility);
    }
}

if (!function_exists('get_zone_color')) {
    function get_zone_color(string $zone_id): string
    {
        return getZoneColor($zone_id);
    }
}
