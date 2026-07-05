<?php
/**
 * Synthetic Test Scenario Helper for Operations Center
 * Allows injection of test data to validate health calculations, rankings, and drift detection
 */

// No DB dependency needed - uses file-based storage via ServiceHelpers

class SyntheticScenarioHelper {
    private $dataPath = __DIR__ . '/../../services/data';
    
    /**
     * Create a test scenario with specified tenant health/drift conditions
     */
    public static function createHealthScenario(string $name, array $tenants): array {
        $scenario = [
            'name' => $name,
            'created_at' => gmdate('c'),
            'tenants' => $tenants,
        ];
        
        self::saveTenantData($tenants);
        
        return $scenario;
    }
    
    /**
     * Healthy Fleet Scenario: All tenants at 100% health, no drift
     */
    public static function healthyFleet(): array {
        $tenants = [];
        for ($i = 1; $i <= 5; $i++) {
            $tenants[] = [
                'tenant_id' => "healthy-tenant-{$i}",
                'health_score' => 100,
                'health_status' => 'Healthy',
                'drift_status' => 'none',
                'install_count' => 1,
                'last_check' => gmdate('c'),
                'health_delta' => 0,
                'volatility_score' => 0,
            ];
        }
        
        return self::createHealthScenario('healthy_fleet', $tenants);
    }
    
    /**
     * Degraded Fleet Scenario: Mix of healthy and at-risk tenants
     */
    public static function degradedFleet(): array {
        $tenants = [
            // Healthy tenants
            [
                'tenant_id' => 'degraded-healthy-1',
                'health_score' => 95,
                'health_status' => 'Healthy',
                'drift_status' => 'none',
                'install_count' => 2,
                'last_check' => gmdate('c'),
                'health_delta' => 5,
                'volatility_score' => 1,
            ],
            [
                'tenant_id' => 'degraded-healthy-2',
                'health_score' => 88,
                'health_status' => 'Healthy',
                'drift_status' => 'none',
                'install_count' => 1,
                'last_check' => gmdate('c'),
                'health_delta' => -2,
                'volatility_score' => 2,
            ],
            // Fair health tenants
            [
                'tenant_id' => 'degraded-fair-1',
                'health_score' => 72,
                'health_status' => 'Fair',
                'drift_status' => 'governance',
                'install_count' => 2,
                'last_check' => gmdate('c'),
                'health_delta' => -8,
                'volatility_score' => 5,
            ],
            [
                'tenant_id' => 'degraded-fair-2',
                'health_score' => 65,
                'health_status' => 'Fair',
                'drift_status' => 'none',
                'install_count' => 1,
                'last_check' => gmdate('c'),
                'health_delta' => -15,
                'volatility_score' => 8,
            ],
            // At-risk tenant
            [
                'tenant_id' => 'degraded-risk-1',
                'health_score' => 45,
                'health_status' => 'Critical',
                'drift_status' => 'revocation',
                'install_count' => 3,
                'last_check' => gmdate('c'),
                'health_delta' => -30,
                'volatility_score' => 15,
            ],
        ];
        
        return self::createHealthScenario('degraded_fleet', $tenants);
    }
    
    /**
     * Drift Detection Scenario: Various drift statuses
     */
    public static function driftScenario(): array {
        $tenants = [
            [
                'tenant_id' => 'drift-none-1',
                'health_score' => 100,
                'health_status' => 'Healthy',
                'drift_status' => 'none',
                'install_count' => 1,
                'last_check' => gmdate('c'),
                'health_delta' => 0,
                'volatility_score' => 0,
            ],
            [
                'tenant_id' => 'drift-none-2',
                'health_score' => 100,
                'health_status' => 'Healthy',
                'drift_status' => 'none',
                'install_count' => 1,
                'last_check' => gmdate('c'),
                'health_delta' => 0,
                'volatility_score' => 0,
            ],
            [
                'tenant_id' => 'drift-governance-1',
                'health_score' => 80,
                'health_status' => 'Healthy',
                'drift_status' => 'governance',
                'install_count' => 1,
                'last_check' => gmdate('c'),
                'health_delta' => -10,
                'volatility_score' => 3,
            ],
            [
                'tenant_id' => 'drift-governance-2',
                'health_score' => 75,
                'health_status' => 'Fair',
                'drift_status' => 'governance',
                'install_count' => 1,
                'last_check' => gmdate('c'),
                'health_delta' => -15,
                'volatility_score' => 5,
            ],
            [
                'tenant_id' => 'drift-revocation-1',
                'health_score' => 50,
                'health_status' => 'Critical',
                'drift_status' => 'revocation',
                'install_count' => 2,
                'last_check' => gmdate('c'),
                'health_delta' => -40,
                'volatility_score' => 20,
            ],
        ];
        
        return self::createHealthScenario('drift_scenario', $tenants);
    }
    
    /**
     * Weighted Health Calculation Scenario: Test weighted average logic
     */
    public static function weightedHealthScenario(): array {
        $tenants = [
            // Low health, many installs (drags down weighted average)
            [
                'tenant_id' => 'weighted-low-high-installs',
                'health_score' => 60,
                'health_status' => 'Fair',
                'drift_status' => 'none',
                'install_count' => 5,
                'last_check' => gmdate('c'),
                'health_delta' => -5,
                'volatility_score' => 4,
            ],
            // High health, few installs (minimal impact)
            [
                'tenant_id' => 'weighted-high-low-installs',
                'health_score' => 100,
                'health_status' => 'Healthy',
                'drift_status' => 'none',
                'install_count' => 1,
                'last_check' => gmdate('c'),
                'health_delta' => 0,
                'volatility_score' => 0,
            ],
            // Medium health, medium installs
            [
                'tenant_id' => 'weighted-medium-medium-installs',
                'health_score' => 80,
                'health_status' => 'Healthy',
                'drift_status' => 'none',
                'install_count' => 2,
                'last_check' => gmdate('c'),
                'health_delta' => 5,
                'volatility_score' => 1,
            ],
        ];
        
        return self::createHealthScenario('weighted_health_scenario', $tenants);
    }
    
    /**
     * Improved Tenants Scenario: Test most improved rankings
     */
    public static function improvedTenantsScenario(): array {
        $tenants = [
            // Large positive delta
            [
                'tenant_id' => 'improved-high-1',
                'health_score' => 90,
                'health_status' => 'Healthy',
                'drift_status' => 'none',
                'install_count' => 1,
                'last_check' => gmdate('c'),
                'health_delta' => 35,  // Significant improvement
                'volatility_score' => 2,
            ],
            // Medium positive delta
            [
                'tenant_id' => 'improved-medium-1',
                'health_score' => 85,
                'health_status' => 'Healthy',
                'drift_status' => 'none',
                'install_count' => 1,
                'last_check' => gmdate('c'),
                'health_delta' => 15,  // Moderate improvement
                'volatility_score' => 2,
            ],
            // Stable tenant
            [
                'tenant_id' => 'stable-1',
                'health_score' => 100,
                'health_status' => 'Healthy',
                'drift_status' => 'none',
                'install_count' => 1,
                'last_check' => gmdate('c'),
                'health_delta' => 0,  // No change
                'volatility_score' => 0,
            ],
            // Declining tenant
            [
                'tenant_id' => 'declining-1',
                'health_score' => 70,
                'health_status' => 'Fair',
                'drift_status' => 'governance',
                'install_count' => 1,
                'last_check' => gmdate('c'),
                'health_delta' => -25,  // Declining
                'volatility_score' => 8,
            ],
        ];
        
        return self::createHealthScenario('improved_tenants_scenario', $tenants);
    }
    
    /**
     * Risk Scenario: Test highest risk rankings
     */
    public static function riskScenario(): array {
        $tenants = [
            // Most at-risk
            [
                'tenant_id' => 'risk-critical-1',
                'health_score' => 25,
                'health_status' => 'Critical',
                'drift_status' => 'revocation',
                'install_count' => 2,
                'last_check' => gmdate('c'),
                'health_delta' => -50,
                'volatility_score' => 25,
            ],
            // High risk
            [
                'tenant_id' => 'risk-high-1',
                'health_score' => 50,
                'health_status' => 'Critical',
                'drift_status' => 'governance',
                'install_count' => 1,
                'last_check' => gmdate('c'),
                'health_delta' => -30,
                'volatility_score' => 15,
            ],
            // Medium risk
            [
                'tenant_id' => 'risk-medium-1',
                'health_score' => 65,
                'health_status' => 'Fair',
                'drift_status' => 'none',
                'install_count' => 1,
                'last_check' => gmdate('c'),
                'health_delta' => -10,
                'volatility_score' => 5,
            ],
            // Low risk
            [
                'tenant_id' => 'risk-low-1',
                'health_score' => 95,
                'health_status' => 'Healthy',
                'drift_status' => 'none',
                'install_count' => 1,
                'last_check' => gmdate('c'),
                'health_delta' => 2,
                'volatility_score' => 0.5,
            ],
        ];
        
        return self::createHealthScenario('risk_scenario', $tenants);
    }
    
    /**
     * Save tenant data to persistent storage
     */
    private static function saveTenantData(array $tenants): void {
        $file = __DIR__ . '/../../services/data/tenants-overview.json';
        
        $data = [
            'tenants' => $tenants,
            'saved_at' => gmdate('c'),
        ];
        
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    /**
     * Reset to default test data
     */
    public static function resetToDefaults(): void {
        // Use the existing test data from the project
        self::saveTenantData([
            [
                'tenant_id' => 'test-tenant-1',
                'health_score' => 100,
                'health_status' => 'Healthy',
                'drift_status' => 'none',
                'install_count' => 1,
                'last_check' => gmdate('c'),
                'health_delta' => 0,
                'volatility_score' => 0,
            ],
        ]);
    }
    
    /**
     * Calculate expected weighted health for scenario
     */
    public static function calculateExpectedHealth(array $tenants): float {
        $totalWeighted = 0;
        $totalInstalls = 0;
        
        foreach ($tenants as $tenant) {
            $health = $tenant['health_score'] ?? 100;
            $installs = $tenant['install_count'] ?? 1;
            $totalWeighted += ($health * $installs);
            $totalInstalls += $installs;
        }
        
        return $totalInstalls > 0 ? round($totalWeighted / $totalInstalls) : 100;
    }
    
    /**
     * Get expected at-risk count for scenario
     */
    public static function getExpectedAtRiskCount(array $tenants): int {
        return count(array_filter($tenants, fn($t) => ($t['health_score'] ?? 100) < 60));
    }
    
    /**
     * Get expected drift breakdown
     */
    public static function getExpectedDriftBreakdown(array $tenants): array {
        $breakdown = [
            'none' => 0,
            'governance' => 0,
            'revocation' => 0,
        ];
        
        foreach ($tenants as $tenant) {
            $status = $tenant['drift_status'] ?? 'none';
            if (isset($breakdown[$status])) {
                $breakdown[$status]++;
            }
        }
        
        return $breakdown;
    }
}

// CLI interface for scenario creation
if (php_sapi_name() === 'cli' && basename($argv[0] ?? '') === basename(__FILE__)) {
    $scenario = $argv[1] ?? 'healthy';
    
    switch ($scenario) {
        case 'healthy':
            $result = SyntheticScenarioHelper::healthyFleet();
            break;
        case 'degraded':
            $result = SyntheticScenarioHelper::degradedFleet();
            break;
        case 'drift':
            $result = SyntheticScenarioHelper::driftScenario();
            break;
        case 'weighted':
            $result = SyntheticScenarioHelper::weightedHealthScenario();
            break;
        case 'improved':
            $result = SyntheticScenarioHelper::improvedTenantsScenario();
            break;
        case 'risk':
            $result = SyntheticScenarioHelper::riskScenario();
            break;
        case 'reset':
            SyntheticScenarioHelper::resetToDefaults();
            echo "Scenario reset to defaults.\n";
            exit(0);
        default:
            echo "Unknown scenario: $scenario\n";
            echo "Available: healthy, degraded, drift, weighted, improved, risk, reset\n";
            exit(1);
    }
    
    echo "Scenario created: " . $result['name'] . "\n";
    echo "Tenants: " . count($result['tenants']) . "\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
}
