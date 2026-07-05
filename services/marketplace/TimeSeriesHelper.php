<?php
/**
 * Time Series Data Aggregation Helper for Fleet Analytics
 * Captures hourly snapshots of platform health, trends, and anomalies
 * Foundation for Phase 3 Cross-Tenant Intelligence features
 */

require_once __DIR__ . '/../../Config/RiskZones.php';

class TimeSeriesHelper {
    private $dataPath = __DIR__ . '/../data/timeseries';
    
    /**
     * Record current platform state as hourly snapshot
     * Called by hourly aggregation job
     */
    public function recordHourlySnapshot(): array {
        $dashboard = $this->getDashboardData();
        $overview = $this->getOverviewData();
        
        $timestamp = gmdate('c');
        $hour = gmdate('Y-m-d H:00:00');
        
        $snapshot = [
            'timestamp' => $timestamp,
            'hour' => $hour,
            'health_score' => $dashboard['health_score'] ?? 100,
            'at_risk_count' => $dashboard['at_risk_count'] ?? 0,
            'critical_count' => $dashboard['critical_count'] ?? 0,
            'total_installs' => $dashboard['total_installs'] ?? 0,
            'remediations_7d' => $dashboard['remediations_7d'] ?? 0,
            'fleet_volatility' => $dashboard['fleet_volatility'] ?? 0,
            'tenant_count' => count($overview),
            'no_drift_count' => 0,
            'governance_drift_count' => 0,
            'revocation_drift_count' => 0,
            'health_distribution' => [
                'critical' => 0,  // < 50
                'at_risk' => 0,   // 50-60
                'fair' => 0,      // 60-75
                'good' => 0,      // 75-90
                'healthy' => 0,   // >= 90
            ],
        ];
        
        // Calculate drift and health distribution
        foreach ($overview as $tenant) {
            $health = $tenant['health_score'] ?? 100;
            $drift = $tenant['drift_status'] ?? 'none';
            
            // Drift counts
            if ($drift === 'none') {
                $snapshot['no_drift_count']++;
            } elseif ($drift === 'governance') {
                $snapshot['governance_drift_count']++;
            } elseif ($drift === 'revocation') {
                $snapshot['revocation_drift_count']++;
            }
            
            // Health distribution
            if ($health < 50) {
                $snapshot['health_distribution']['critical']++;
            } elseif ($health < 60) {
                $snapshot['health_distribution']['at_risk']++;
            } elseif ($health < 75) {
                $snapshot['health_distribution']['fair']++;
            } elseif ($health < 90) {
                $snapshot['health_distribution']['good']++;
            } else {
                $snapshot['health_distribution']['healthy']++;
            }
        }
        
        // Append to hourly metrics file
        $this->appendSnapshot($snapshot);
        
        return $snapshot;
    }
    
    /**
     * Get time series data with trend calculations
     */
    public function getTimeSeries(
        ?string $tenantId = null,
        ?string $metric = 'health_score',
        string $period = 'hourly',
        int $daysBack = 7,
        int $forecastHorizon = 0
    ): array {
        $metric = $metric ?: 'health_score';
        $daysBack = max(1, $daysBack);
        $forecastHorizon = max(0, $forecastHorizon);

        if ($tenantId) {
            $dataPoints = $this->loadTenantHistory($tenantId, $daysBack);
        } else {
            $dataPoints = $this->loadTimeSeriesData($daysBack);
        }

        if ($period !== 'hourly') {
            $dataPoints = $this->resampleByPeriod($dataPoints, $period);
        }

        $stats = $this->calculateStatistics($dataPoints, $metric);
        $trend = $this->calculateTrend($dataPoints, $metric);
        $forecast = $this->calculateForecast($dataPoints, $metric, $period, $forecastHorizon);

        return [
            'tenant_id' => $tenantId ?? null,
            'metric' => $metric,
            'period' => $period,
            'days_back' => $daysBack,
            'forecast_horizon' => $forecastHorizon,
            'data_points' => $dataPoints,
            'forecast' => $forecast,
            'statistics' => [
                'current_value' => $stats['current'] ?? null,
                '7d_avg' => $stats['avg'] ?? null,
                '7d_min' => $stats['min'] ?? null,
                '7d_max' => $stats['max'] ?? null,
                '7d_stddev' => $stats['stddev'] ?? null,
                'trend_direction' => $trend['direction'] ?? 'stable',
                'trend_velocity' => $trend['velocity'] ?? 0,
                'trend_confidence' => $trend['confidence'] ?? 0,
            ],
            'cached_at' => gmdate('c'),
        ];
    }
    
    /**
     * Calculate simple linear regression for trend
     */
    private function calculateTrend(array $dataPoints, string $metric): array {
        if (count($dataPoints) < 2) {
            return ['direction' => 'stable', 'velocity' => 0, 'confidence' => 0];
        }
        
        // Extract metric values
        $values = [];
        foreach ($dataPoints as $point) {
            $value = $this->extractMetricValue($point, $metric);
            if ($value !== null) {
                $values[] = $value;
            }
        }
        
        if (count($values) < 2) {
            return ['direction' => 'stable', 'velocity' => 0, 'confidence' => 0];
        }
        
        // Simple linear regression
        $n = count($values);
        $x = array_keys($values);
        $y = array_values($values);
        
        $xMean = array_sum($x) / $n;
        $yMean = array_sum($y) / $n;
        
        $numerator = 0;
        $denominator = 0;
        $rNumerator = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $xDiff = $x[$i] - $xMean;
            $yDiff = $y[$i] - $yMean;
            $numerator += $xDiff * $yDiff;
            $denominator += $xDiff * $xDiff;
            $rNumerator += $yDiff * $yDiff;
        }
        
        $slope = abs($denominator) > 1e-9 ? $numerator / $denominator : 0;
        
        // Calculate R²
        $yVariance = 0;
        for ($i = 0; $i < $n; $i++) {
            $predicted = $yMean + $slope * ($x[$i] - $xMean);
            $yVariance += pow($y[$i] - $predicted, 2);
        }

        $r2 = abs($rNumerator) > 1e-9 ? 1 - ($yVariance / $rNumerator) : 0;
        $r2 = max(0, min(1, $r2)); // Clamp 0-1
        
        // Classify direction
        if ($slope > 1) {
            $direction = 'improving';
        } elseif ($slope < -1) {
            $direction = 'degrading';
        } else {
            $direction = 'stable';
        }
        
        return [
            'direction' => $direction,
            'velocity' => round($slope, 2),
            'confidence' => round($r2, 2),
        ];
    }

    /**
     * Generate a short forecast series for the requested metric
     */
    private function calculateForecast(array $dataPoints, string $metric, string $period, int $horizon): array {
        if ($horizon < 1 || count($dataPoints) < 2) {
            return [];
        }

        $trend = $this->calculateTrend($dataPoints, $metric);
        $slope = $trend['velocity'];
        $interval = $this->periodToSeconds($period);
        $lastPoint = end($dataPoints);
        $lastTimestamp = strtotime($lastPoint['timestamp']) ?: time();
        $lastValue = $this->extractMetricValue($lastPoint, $metric) ?? 0;

        $forecast = [];
        for ($i = 1; $i <= $horizon; $i++) {
            $predicted = max(0, min(100, $lastValue + ($slope * $i)));
            $forecast[] = [
                'timestamp' => gmdate('c', $lastTimestamp + ($interval * $i)),
                'predicted_value' => round($predicted, 2),
                'period' => $period,
            ];
        }

        return $forecast;
    }

    /**
     * Convert a period name into seconds for forecast scheduling
     */
    private function periodToSeconds(string $period): int {
        return match ($period) {
            'weekly' => 604800,
            'monthly' => 2592000,
            'daily' => 86400,
            default => 3600,
        };
    }

    /**
     * Build a multi-tenant comparison payload for the requested metric
     */
    public function getTenantComparisonSeries(array $tenantIds, string $metric = 'health_score', string $period = 'hourly', int $daysBack = 7, int $forecastHorizon = 0): array {
        $tenantIds = array_values(array_filter(array_map('trim', $tenantIds), fn($id) => $id !== ''));
        $items = [];

        foreach ($tenantIds as $tenantId) {
            $dataPoints = $this->loadTenantHistory($tenantId, $daysBack);
            if ($period !== 'hourly') {
                $dataPoints = $this->resampleByPeriod($dataPoints, $period);
            }

            $stats = $this->calculateStatistics($dataPoints, $metric);
            $trend = $this->calculateTrend($dataPoints, $metric);
            $forecast = $this->calculateForecast($dataPoints, $metric, $period, $forecastHorizon);
            $latestPoint = !empty($dataPoints) ? end($dataPoints) : null;
            $latestZone = null;

            if ($latestPoint && isset($latestPoint['health_score']) && isset($latestPoint['volatility'])) {
                $latestZone = \GD\Workflow\Config\formatZoneForUI((float)$latestPoint['health_score'], (float)$latestPoint['volatility']);
            }

            $items[] = [
                'tenant_id' => $tenantId,
                'metric' => $metric,
                'period' => $period,
                'days_back' => $daysBack,
                'data_points' => $dataPoints,
                'forecast' => $forecast,
                'statistics' => [
                    'current_value' => $stats['current'] ?? null,
                    '7d_avg' => $stats['avg'] ?? null,
                    '7d_min' => $stats['min'] ?? null,
                    '7d_max' => $stats['max'] ?? null,
                    '7d_stddev' => $stats['stddev'] ?? null,
                    'trend_direction' => $trend['direction'] ?? 'stable',
                    'trend_velocity' => $trend['velocity'] ?? 0,
                    'trend_confidence' => $trend['confidence'] ?? 0,
                ],
                'latest_point' => $latestPoint,
                'latest_zone' => $latestZone,
            ];
        }

        $summaryValues = array_filter(array_map(fn($item) => $item['statistics']['current_value'], $items), fn($value) => $value !== null);

        return [
            'tenant_ids' => $tenantIds,
            'metric' => $metric,
            'period' => $period,
            'days_back' => $daysBack,
            'forecast_horizon' => $forecastHorizon,
            'comparisons' => $items,
            'comparison_summary' => [
                'count' => count($items),
                'average_current' => !empty($summaryValues) ? round(array_sum($summaryValues) / count($summaryValues), 2) : null,
            ],
            'cached_at' => gmdate('c'),
        ];
    }

    /**
     * Compute drift analysis: compare each tenant's health to fleet baseline
     * Returns metrics for drift detection and anomaly identification
     */
    public function computeDriftAnalysis(string $metric = 'health_score', int $daysBack = 7, string $sortBy = 'drift_magnitude'): array {
        $overview = $this->getOverviewData();
        if (empty($overview)) {
            return [
                'fleet_average' => null,
                'fleet_stddev' => null,
                'tenant_count' => 0,
                'tenants' => [],
                'computed_at' => gmdate('c'),
            ];
        }

        // Extract current health for each tenant
        $healthValues = [];
        $tenantMetrics = [];

        foreach ($overview as $tenant) {
            $tenantId = $tenant['tenant_id'] ?? $tenant['id'] ?? null;
            $currentHealth = $tenant['health_score'] ?? 50;
            $currentVolatility = $tenant['volatility'] ?? $tenant['fleet_volatility'] ?? 0;
            
            if ($tenantId) {
                $healthValues[] = $currentHealth;
                $tenantMetrics[$tenantId] = [
                    'tenant_id' => $tenantId,
                    'name' => $tenant['name'] ?? $tenantId,
                    'current_health' => (float)$currentHealth,
                    'current_volatility' => (float)$currentVolatility,
                    'status' => $tenant['status'] ?? 'active',
                ];
            }
        }

        // Calculate fleet baseline
        $fleetAverage = !empty($healthValues) ? array_sum($healthValues) / count($healthValues) : 50;
        $fleetStddev = $this->calculateStandardDeviation($healthValues);

        // Compute drift for each tenant
        $driftEntries = [];
        foreach ($tenantMetrics as $tenantId => $metrics) {
            $health = $metrics['current_health'];
            $drift = $health - $fleetAverage;
            $driftSigma = $fleetStddev > 0 ? $drift / $fleetStddev : 0;
            
            // Get trend for this tenant
            $tenantHistory = $this->loadTenantHistory($tenantId, $daysBack);
            $trend = $this->calculateTrend($tenantHistory, $metric);

            $driftEntries[] = array_merge($metrics, [
                'fleet_average' => round($fleetAverage, 2),
                'drift_magnitude' => round($drift, 2),
                'drift_sigma' => round($driftSigma, 2),
                'drift_direction' => $drift > 0 ? 'above_baseline' : ($drift < 0 ? 'below_baseline' : 'at_baseline'),
                'is_anomalous' => abs($driftSigma) > 1.5,  // Flag if > 1.5 sigma
                'trend_direction' => $trend['direction'] ?? 'stable',
                'trend_velocity' => $trend['velocity'] ?? 0,
            ]);
        }

        // Sort by requested metric
        usort($driftEntries, function($a, $b) use ($sortBy) {
            $aVal = $a[$sortBy] ?? 0;
            $bVal = $b[$sortBy] ?? 0;
            return $bVal <=> $aVal;  // Descending
        });

        return [
            'metric' => $metric,
            'period' => $daysBack . '_days',
            'fleet_average' => round($fleetAverage, 2),
            'fleet_stddev' => round($fleetStddev, 2),
            'tenant_count' => count($tenantMetrics),
            'anomalous_count' => array_sum(array_map(fn($e) => $e['is_anomalous'] ? 1 : 0, $driftEntries)),
            'tenants' => $driftEntries,
            'computed_at' => gmdate('c'),
        ];
    }

    /**
     * Calculate standard deviation from array of values
     */
    private function calculateStandardDeviation(array $values): float {
        if (empty($values)) {
            return 0;
        }
        $count = count($values);
        $mean = array_sum($values) / $count;
        $variance = 0;
        foreach ($values as $val) {
            $variance += pow($val - $mean, 2);
        }
        return sqrt($variance / $count);
    }

    /**
     * Extract metric value from data point
     */
    private function extractMetricValue(array $point, string $metric): ?float {
        $map = [
            'health_score' => 'health_score',
            'volatility' => 'fleet_volatility',
            'at_risk' => 'at_risk_count',
            'critical' => 'critical_count',
            'drift_rate' => function($p) {
                $total = ($p['no_drift_count'] ?? 0) +
                         ($p['governance_drift_count'] ?? 0) +
                         ($p['revocation_drift_count'] ?? 0);
                return $total > 0 ? (($p['governance_drift_count'] ?? 0) +
                                     ($p['revocation_drift_count'] ?? 0)) / $total : 0;
            },
        ];
        
        if (!isset($map[$metric])) {
            return null;
        }
        
        $key = $map[$metric];
        if (is_callable($key)) {
            return $key($point);
        }
        
        return isset($point[$key]) ? (float)$point[$key] : null;
    }
    
    /**
     * Calculate statistics for data points
     */
    private function calculateStatistics(array $dataPoints, string $metric): array {
        $values = [];
        foreach ($dataPoints as $point) {
            $value = $this->extractMetricValue($point, $metric);
            if ($value !== null) {
                $values[] = $value;
            }
        }
        
        if (empty($values)) {
            return [];
        }
        
        $count = count($values);
        $sum = array_sum($values);
        $avg = $sum / $count;
        $min = min($values);
        $max = max($values);
        
        // Standard deviation
        $sumSquaredDiff = 0;
        foreach ($values as $value) {
            $sumSquaredDiff += pow($value - $avg, 2);
        }
        $stddev = sqrt($sumSquaredDiff / $count);
        
        return [
            'current' => end($values),
            'avg' => round($avg, 2),
            'min' => $min,
            'max' => $max,
            'stddev' => round($stddev, 2),
        ];
    }
    
    /**
     * Aggregate fleet-wide metrics from all tenants
     */
    private function aggregateFleetMetrics(array $dataPoints): array {
        // Currently just fleet snapshots; could add tenant-level data in future
        return $dataPoints;
    }
    
    /**
     * Load time series data from file storage
     */
    private function loadTimeSeriesData(int $daysBack): array {
        @mkdir($this->dataPath, 0755, true);
        $file = $this->dataPath . '/fleet-aggregate.jsonl';
        
        if (!file_exists($file)) {
            return [];
        }
        
        $cutoffTime = time() - ($daysBack * 86400);
        $dataPoints = [];
        
        $handle = fopen($file, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $point = json_decode(trim($line), true);
                if ($point && isset($point['timestamp'])) {
                    $pointTime = strtotime($point['timestamp']);
                    if ($pointTime >= $cutoffTime) {
                        $dataPoints[] = $point;
                    }
                }
            }
            fclose($handle);
        }
        
        return $dataPoints;
    }

    /**
     * Load tenant-specific history for time series lookups
     */
    private function loadTenantHistory(string $tenantId, int $daysBack): array {
        $file = ServiceHelpers::dataPath('marketplace', "tenant_history_{$tenantId}.json");
        if (!file_exists($file)) {
            return [];
        }

        $history = json_decode(file_get_contents($file), true);
        if (!is_array($history)) {
            return [];
        }

        $cutoffTime = time() - ($daysBack * 86400);
        return array_values(array_filter($history, function ($point) use ($cutoffTime) {
            if (!isset($point['timestamp'])) {
                return false;
            }
            $pointTime = strtotime($point['timestamp']);
            return $pointTime !== false && $pointTime >= $cutoffTime;
        }));
    }

    /**
     * Resample time series into daily/weekly buckets when requested.
     */
    private function resampleByPeriod(array $dataPoints, string $period): array {
        if ($period === 'hourly' || empty($dataPoints)) {
            return $dataPoints;
        }

        $grouped = [];
        foreach ($dataPoints as $point) {
            $timestamp = isset($point['timestamp']) ? strtotime($point['timestamp']) : false;
            if ($timestamp === false) {
                continue;
            }

            switch ($period) {
                case 'weekly':
                    $bucket = gmdate('o-W', $timestamp);
                    break;
                case 'monthly':
                    $bucket = gmdate('Y-m', $timestamp);
                    break;
                case 'daily':
                default:
                    $bucket = gmdate('Y-m-d', $timestamp);
                    break;
            }

            // Keep the most recent value for the bucket.
            $grouped[$bucket] = $point;
        }

        return array_values($grouped);
    }
    private function appendSnapshot(array $snapshot): void {
        @mkdir($this->dataPath, 0755, true);
        $file = $this->dataPath . '/fleet-aggregate.jsonl';
        
        $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES);
        file_put_contents($file, $json . "\n", FILE_APPEND);
    }
    
    /**
     * Get platform cache path for the marketplace service
     */
    private function getPlatformCachePath(): string {
        return ServiceHelpers::dataPath('marketplace', 'platform_cache.json');
    }

    /**
     * Get dashboard data (from platform aggregation)
     */
    private function getDashboardData(): array {
        $cacheFile = $this->getPlatformCachePath();
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            return $data['dashboard'] ?? [];
        }
        return [];
    }
    
    /**
     * Get overview data (from platform aggregation)
     */
    private function getOverviewData(): array {
        $cacheFile = $this->getPlatformCachePath();
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            return $data['overview'] ?? [];
        }
        return [];
    }
    
    /**
     * Prune old data (keep last 90 days)
     */
    public function pruneOldData(int $keepDays = 90): int {
        @mkdir($this->dataPath, 0755, true);
        $file = $this->dataPath . '/fleet-aggregate.jsonl';
        
        if (!file_exists($file)) {
            return 0;
        }
        
        $cutoffTime = time() - ($keepDays * 86400);
        $dataPoints = [];
        $pruned = 0;
        
        $handle = fopen($file, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $point = json_decode(trim($line), true);
                if ($point && isset($point['timestamp'])) {
                    $pointTime = strtotime($point['timestamp']);
                    if ($pointTime >= $cutoffTime) {
                        $dataPoints[] = $point;
                    } else {
                        $pruned++;
                    }
                }
            }
            fclose($handle);
        }
        
        // Rewrite file with only recent data
        file_put_contents($file, '');
        foreach ($dataPoints as $point) {
            $json = json_encode($point, JSON_UNESCAPED_SLASHES);
            file_put_contents($file, $json . "\n", FILE_APPEND);
        }
        
        return $pruned;
    }
    
    /**
     * Get current fleet snapshot for immediate use
     */
    public function getCurrentSnapshot(): array {
        $dataPoints = $this->loadTimeSeriesData(1); // Last 24 hours
        return !empty($dataPoints) ? end($dataPoints) : [];
    }
}

// CLI interface
if (php_sapi_name() === 'cli' && basename($argv[0] ?? '') === basename(__FILE__)) {
    $action = $argv[1] ?? 'record';
    $helper = new TimeSeriesHelper();
    
    switch ($action) {
        case 'record':
            $snapshot = $helper->recordHourlySnapshot();
            echo "Snapshot recorded: " . json_encode($snapshot) . "\n";
            break;
        
        case 'prune':
            $days = (int)($argv[2] ?? 90);
            $pruned = $helper->pruneOldData($days);
            echo "Pruned $pruned data points, keeping last $days days\n";
            break;
        
        case 'current':
            $snapshot = $helper->getCurrentSnapshot();
            echo json_encode($snapshot, JSON_PRETTY_PRINT) . "\n";
            break;
        
        default:
            echo "Usage: php TimeSeriesHelper.php {record|prune|current}\n";
            exit(1);
    }
}
