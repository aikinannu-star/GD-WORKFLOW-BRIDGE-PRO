<?php
require_once __DIR__ . '/../services/lib/ServiceHelpers.php';
require_once __DIR__ . '/../services/marketplace/TimeSeriesHelper.php';

function write_platform_cache(array $overview) {
    $payload = [
        'dashboard' => [
            'health_score' => round(array_sum(array_column($overview, 'health_score')) / max(1, count($overview)), 2),
            'fleet_volatility' => 0,
            'at_risk_count' => 0,
            'critical_count' => 0,
            'total_installs' => 0,
        ],
        'overview' => $overview,
        'cached_at' => gmdate('c'),
    ];

    ServiceHelpers::saveJson('marketplace', 'platform_cache.json', $payload);
}

function write_tenant_history(string $tenantId, array $values, int $daysBack = 7) {
    $points = [];
    $now = time();
    $interval = 86400; // daily points
    for ($i = 0; $i < count($values); $i++) {
        $points[] = [
            'timestamp' => gmdate('c', $now - ($i * $interval)),
            'health_score' => (float)$values[$i],
            'fleet_volatility' => 0,
        ];
    }

    ServiceHelpers::saveJson('marketplace', "tenant_history_{$tenantId}.json", $points);
}

function run_scenario(string $name, array $healthValuesPerTenant) {
    echo "\n=== Scenario: {$name} ===\n";

    $overview = [];
    foreach ($healthValuesPerTenant as $tenantId => $values) {
        $current = end($values);
        $overview[] = [
            'tenant_id' => $tenantId,
            'name' => $tenantId,
            'health_score' => (float)$current,
            'volatility' => 0,
            'status' => 'active',
        ];
        write_tenant_history($tenantId, $values);
    }

    write_platform_cache($overview);

    $helper = new TimeSeriesHelper();
    $analysis = $helper->computeDriftAnalysis('health_score', 7, 'drift_magnitude');

    echo json_encode($analysis, JSON_PRETTY_PRINT) . "\n";
}

// Define tenant ids
$tenants = [
    'tenant-A', 'tenant-B', 'tenant-C', 'tenant-D', 'tenant-E'
];

// Healthy fleet
$healthy = [
    'tenant-A' => [100,100,100,100,100,100,100],
    'tenant-B' => [100,99,100,100,100,100,100],
    'tenant-C' => [98,99,100,100,100,100,99],
    'tenant-D' => [100,100,100,100,100,100,100],
    'tenant-E' => [100,100,100,100,100,100,100],
];

// Mixed fleet
$mixed = [
    'tenant-A' => [100,100,95,95,96,95,95],
    'tenant-B' => [95,94,93,95,95,95,95],
    'tenant-C' => [88,87,86,88,89,88,88],
    'tenant-D' => [70,72,71,70,69,71,70],
    'tenant-E' => [55,56,57,55,54,55,55],
];

// Degrading fleet
$degrading = [
    'tenant-A' => [100,98,95,90,85,80,75],
    'tenant-B' => [98,95,90,80,70,55,45],
    'tenant-C' => [60,55,50,45,40,35,30],
    'tenant-D' => [40,35,30,25,20,18,15],
    'tenant-E' => [100,99,98,95,90,85,80],
];

run_scenario('Healthy Fleet', $healthy);
run_scenario('Mixed Fleet', $mixed);
run_scenario('Degrading Fleet', $degrading);

echo "\nSynthetic harness complete.\n";
