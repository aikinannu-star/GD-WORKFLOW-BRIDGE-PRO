#!/usr/bin/env php
<?php
/**
 * Development helper: Generate synthetic time series data for trend analysis
 * Creates hourly snapshots over N days to simulate realistic time series
 */

require_once __DIR__ . '/../../lib/ServiceHelpers.php';
require_once __DIR__ . '/../TimeSeriesHelper.php';

$daysBack = (int)($argv[1] ?? 7);
$snapshots = $daysBack * 24;

echo "Generating $snapshots hourly snapshots ($daysBack days)...\n";

// Ensure data directory exists
@mkdir(__DIR__ . '/../data/timeseries', 0755, true);

$dataPath = __DIR__ . '/../data/timeseries/fleet-aggregate.jsonl';
file_put_contents($dataPath, ''); // Clear file

// Generate synthetic snapshots
$baseTime = time() - ($daysBack * 86400);

for ($i = 0; $i < $snapshots; $i++) {
    $time = $baseTime + ($i * 3600);
    
    // Create realistic trend: slightly improving health
    $healthBase = 85;
    $healthTrend = ($i / $snapshots) * 10;  // Trend from 85 to 95
    $healthNoise = rand(-5, 5);
    $health = max(0, min(100, $healthBase + $healthTrend + $healthNoise));
    
    // Volatility decreases as health improves
    $volatilityVal = 5 - ($healthTrend / 2) + (rand(-2, 2) / 10);
    $volatility = max(0.5, $volatilityVal);
    
    // At-risk tenants decrease
    $atRiskVal = 3 - (int)($healthTrend / 3);
    $atRisk = max(0, $atRiskVal);
    
    // Drift status improves
    $noDriftVal = 5 - (int)($i / 100);
    $noDrift = max(3, $noDriftVal);
    $govDriftVal = 2 - (int)($i / 200);
    $govDrift = max(0, $govDriftVal);
    $revDriftVal = 1 - (int)($i / 300);
    $revDrift = max(0, $revDriftVal);
    
    $snapshot = [
        'timestamp' => gmdate('c', $time),
        'hour' => gmdate('Y-m-d H:00:00', $time),
        'health_score' => round($health),
        'at_risk_count' => $atRisk,
        'critical_count' => max(0, $atRisk - 1),
        'total_installs' => 10,
        'remediations_7d' => rand(0, 2),
        'fleet_volatility' => round($volatility, 2),
        'tenant_count' => 7,
        'no_drift_count' => $noDrift,
        'governance_drift_count' => $govDrift,
        'revocation_drift_count' => $revDrift,
        'health_distribution' => [
            'critical' => $atRisk > 1 ? 1 : 0,
            'at_risk' => max(0, $atRisk - 1),
            'fair' => max(0, 2 - (int)($healthTrend / 5)),
            'good' => max(0, 2 - (int)($healthTrend / 10)),
            'healthy' => max(2, 7 - $atRisk - max(0, 2 - (int)($healthTrend / 5))),
        ],
    ];
    
    $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES);
    file_put_contents($dataPath, $json . "\n", FILE_APPEND);
    
    if (($i + 1) % 24 === 0) {
        echo "  Generated " . ($i + 1) . " snapshots (" . (int)(($i + 1) / 24) . " days)\n";
    }
}

echo "✓ Generated $snapshots snapshots in $dataPath\n";
echo "\nTime series data ready for trend visualization:\n";
echo "  - Health trending: 85 → 95\n";
echo "  - Volatility: Decreasing\n";
echo "  - At-risk tenants: Decreasing\n";
echo "  - Drift: Improving\n";
echo "\nTest with: curl 'http://127.0.0.1:8006/api/v1/marketplace/platform/timeseries?metric=health_score&days_back=7'\n";
