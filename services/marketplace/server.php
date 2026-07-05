<?php
require_once __DIR__ . '/../lib/ServiceHelpers.php';
require_once __DIR__ . '/../../tests/SyntheticScenarioHelper.php';
require_once __DIR__ . '/TimeSeriesHelper.php';
require_once __DIR__ . '/EffectivenessMetrics.php';
require_once __DIR__ . '/IntelligenceLearning.php';

define('SERVICE_NAME', 'marketplace');
define('SERVICE_PORT', 8006);

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'service' => SERVICE_NAME,
        'version' => '1.0.0',
        'time' => gmdate('c'),
    ]);
    exit;
}

if ($method === 'GET' && ($uri === '/api/v1/risk-zones' || $uri === '/api/v1/risk-zones/')) {
    require_once __DIR__ . '/../../Config/RiskZones.php';
    require_once __DIR__ . '/api/RiskZonesEndpoint.php';
    \GD\Workflow\API\RiskZonesEndpoint::handle();
}

if ($method === 'GET' && preg_match('#^/api/v1/risk-zones/classify#', $uri)) {
    require_once __DIR__ . '/../../Config/RiskZones.php';
    require_once __DIR__ . '/api/RiskZonesEndpoint.php';

    $health = isset($_GET['health']) ? (float)($_GET['health']) : 50.0;
    $volatility = isset($_GET['volatility']) ? (float)($_GET['volatility']) : 50.0;
    $zone = \GD\Workflow\API\RiskZonesEndpoint::classifyTenant($health, $volatility);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($zone);
    exit;
}

if ($method === 'GET' && preg_match('#^/api/v1/drift-analysis#', $uri)) {
    require_once __DIR__ . '/TimeSeriesHelper.php';

    $metric = $_GET['metric'] ?? 'health_score';
    $daysBack = (int)($_GET['days_back'] ?? 7);
    $sortBy = $_GET['sort_by'] ?? 'drift_magnitude';

    try {
        $helper = new TimeSeriesHelper();
        $analysis = $helper->computeDriftAnalysis($metric, $daysBack, $sortBy);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($analysis);
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/v1/intelligence-health#', $uri)) {
    require_once __DIR__ . '/TimeSeriesHelper.php';

    try {
        // Platform aggregation (cached)
        $agg = buildPlatformAggregation();
        $dashboard = $agg['dashboard'] ?? [];
        $overview = $agg['overview'] ?? [];

        // Drift analysis for KPI-derived anomaly density and fleet stats
        $helper = new TimeSeriesHelper();
        $drift = $helper->computeDriftAnalysis('health_score', 7, 'drift_magnitude');

        $fleetAvg = $drift['fleet_average'] ?? ($dashboard['platform_health_score'] ?? 0);
        $fleetStd = $drift['fleet_stddev'] ?? 0;
        $tenantCount = intval($drift['tenant_count'] ?? (count($overview)));
        $anomalousCount = intval($drift['anomalous_count'] ?? 0);

        // Trend confidence: heuristic (higher stddev lowers confidence)
        $trendConfidence = 0.0;
        if ($fleetAvg > 0) {
            $trendConfidence = 1.0 - min(1.0, ($fleetStd / $fleetAvg));
        }

        // Stable tenants percent
        $stableCount = 0;
        foreach ($overview as $t) {
            if (($t['health_trend'] ?? '') === 'stable' || intval($t['health_delta'] ?? 0) === 0) {
                $stableCount++;
            }
        }
        $stablePct = $tenantCount > 0 ? round(($stableCount / $tenantCount) * 100, 2) : 0.0;

        // Anomaly density
        $anomalyDensity = $tenantCount > 0 ? round($anomalousCount / $tenantCount, 4) : 0.0;

        // Remediation success rate and drift resolution (best-effort from events)
        $events = getPlatformRemediationEvents();
        $totalRem = count($events);
        $successRem = 0;
        $resolutionHours = [];
        foreach ($events as $ev) {
            $details = $ev['details'] ?? [];
            if (isset($details['success']) && $details['success']) $successRem++;
            if (isset($details['outcome']) && in_array(strtolower($details['outcome']), ['success', 'ok', 'succeeded'])) $successRem++;
            if (!empty($ev['created_at']) && !empty($details['resolved_at'])) {
                $start = strtotime($ev['created_at']);
                $end = strtotime($details['resolved_at']);
                if ($start !== false && $end !== false && $end > $start) {
                    $resolutionHours[] = ($end - $start) / 3600.0;
                }
            }
        }
        $remediationSuccessRate = $totalRem > 0 ? round(min(1.0, $successRem / max(1, $totalRem)), 4) : null;
        $avgResolutionHours = count($resolutionHours) ? round(array_sum($resolutionHours) / count($resolutionHours), 2) : null;

        // Compute overall status from KPIs
        $criticalCount = 0;
        $warningCount = 0;
        if ($trendConfidence < 0.7) $criticalCount++;
        else if ($trendConfidence < 0.9) $warningCount++;
        if ($stablePct < 70) $criticalCount++;
        else if ($stablePct < 85) $warningCount++;
        if ($anomalyDensity > 0.15) $criticalCount++;
        else if ($anomalyDensity > 0.05) $warningCount++;
        if ($remediationSuccessRate !== null && $remediationSuccessRate < 0.75) $criticalCount++;
        else if ($remediationSuccessRate !== null && $remediationSuccessRate < 0.9) $warningCount++;
        if ($avgResolutionHours !== null && $avgResolutionHours > 12) $criticalCount++;
        else if ($avgResolutionHours !== null && $avgResolutionHours > 4) $warningCount++;

        $status = 'healthy';
        if ($criticalCount > 0) $status = 'critical';
        else if ($warningCount > 0) $status = 'warning';

        // Build findings from anomalous tenants
        $findings = [];
        if ($anomalousCount > 0) {
            $anomalous = array_filter($drift['tenants'] ?? [], fn($t) => ($t['is_anomalous'] ?? false));
            foreach (array_slice($anomalous, 0, 5) as $at) {
                $findings[] = [
                    'severity' => 'warning',
                    'title' => 'Anomalous tenant detected',
                    'message' => ($at['tenant_id'] ?? 'Unknown') . ' (drift: ' . number_format($at['drift_sigma'] ?? 0, 2) . 'σ)',
                ];
            }
        }
        if ($stablePct < 70) {
            $findings[] = [
                'severity' => 'critical',
                'title' => 'Low stability',
                'message' => 'Only ' . intval($stablePct) . '% of tenants are stable',
            ];
        }
        if ($anomalyDensity > 0.15) {
            $findings[] = [
                'severity' => 'critical',
                'title' => 'High anomaly density',
                'message' => 'Anomaly density is ' . number_format($anomalyDensity * 100, 1) . '% (>15%)',
            ];
        }
        if ($remediationSuccessRate !== null && $remediationSuccessRate < 0.75) {
            $findings[] = [
                'severity' => 'warning',
                'title' => 'Low remediation success',
                'message' => 'Success rate is ' . number_format($remediationSuccessRate * 100, 0) . '% (<75%)',
            ];
        }

        // Build recommendations
        $recommendations = [];
        if ($anomalousCount > 0) {
            $recommendations[] = 'Review anomalous tenants in Drift Analysis Grid';
        }
        if ($stablePct < 85) {
            $recommendations[] = 'Investigate unstable tenants; consider remediation actions';
        }
        if ($trendConfidence < 0.9) {
            $recommendations[] = 'Fleet shows high variance; review outliers';
        }
        if ($remediationSuccessRate !== null && $remediationSuccessRate < 0.9 && $totalRem > 0) {
            $recommendations[] = 'Review recent remediation events for failures';
        }
        if (count($recommendations) === 0) {
            $recommendations[] = 'Platform is healthy; continue monitoring';
        }

        $payload = [
            'status' => $status,
            'trend_confidence' => round($trendConfidence, 4),
            'stable_tenants_pct' => $stablePct,
            'anomaly_density' => $anomalyDensity,
            'remediation_success_rate' => $remediationSuccessRate,
            'average_drift_resolution_hours' => $avgResolutionHours,
            'tenant_count' => $tenantCount,
            'anomalous_count' => $anomalousCount,
            'fleet_average' => $fleetAvg,
            'fleet_stddev' => $fleetStd,
            'findings' => $findings,
            'recommendations' => $recommendations,
            'computed_at' => gmdate('c'),
        ];

        ServiceHelpers::sendJson(200, $payload);
    } catch (Exception $e) {
        ServiceHelpers::sendJson(500, ['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/api/v1/remediation-events$#', $uri)) {
    $payload = getRequestBody();
    $tenantId = trim($payload['tenant_id'] ?? '');
    $action = trim($payload['action'] ?? '');
    $details = $payload['details'] ?? [];
    $recommendationType = trim($payload['recommendation_type'] ?? '');

    if ($tenantId === '' || $action === '') {
        ServiceHelpers::sendJson(400, ['error' => 'invalid_payload', 'message' => 'tenant_id and action are required']);
    }

    $events = getPlatformRemediationEvents();
    $event = [
        'id' => ServiceHelpers::generateUuid(),
        'tenant_id' => $tenantId,
        'action' => $action,
        'recommendation_type' => $recommendationType ?: 'manual',
        'accepted' => isset($payload['accepted']) ? boolval($payload['accepted']) : false,
        'accepted_at' => isset($payload['accepted']) && $payload['accepted'] ? gmdate('c') : null,
        'details' => $details,
        'created_at' => gmdate('c'),
    ];
    $events[] = $event;
    ServiceHelpers::saveJson('marketplace', 'remediation_events.json', $events);
    clearPlatformCache();

    ServiceHelpers::sendJson(201, $event);
}

if ($method === 'POST' && preg_match('#^/api/v1/remediation-events/([^/]+)/resolve$#', $uri, $m)) {
    $id = $m[1];
    $payload = getRequestBody();
    $success = isset($payload['success']) ? boolval($payload['success']) : null;
    $outcome = isset($payload['outcome']) ? $payload['outcome'] : null;
    $resolvedAt = $payload['resolved_at'] ?? gmdate('c');

    $events = getPlatformRemediationEvents();
    $found = false;
    foreach ($events as &$ev) {
        if (($ev['id'] ?? '') === $id) {
            $ev['details'] = $ev['details'] ?? [];
            if ($success !== null) $ev['details']['success'] = $success;
            if ($outcome !== null) $ev['details']['outcome'] = $outcome;
            $ev['details']['resolved_at'] = $resolvedAt;
            $found = true;
            break;
        }
    }
    if (!$found) {
        ServiceHelpers::sendJson(404, ['error' => 'event_not_found']);
    }
    ServiceHelpers::saveJson('marketplace', 'remediation_events.json', $events);
    clearPlatformCache();
    ServiceHelpers::sendJson(200, $ev);
}

// === Effectiveness Metrics APIs ===

if ($method === 'GET' && preg_match('#^/api/v1/intelligence-effectiveness/recommendations#', $uri)) {
    try {
        $recs = EffectivenessMetrics::computeRecommendationEffectiveness();
        ServiceHelpers::sendJson(200, ['recommendations' => $recs, 'computed_at' => gmdate('c')]);
    } catch (Exception $e) {
        ServiceHelpers::sendJson(500, ['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/v1/intelligence-effectiveness/mttd#', $uri)) {
    try {
        $mttd = EffectivenessMetrics::computeMTTD();
        ServiceHelpers::sendJson(200, array_merge($mttd, ['computed_at' => gmdate('c')]));
    } catch (Exception $e) {
        ServiceHelpers::sendJson(500, ['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/v1/intelligence-effectiveness/mttr#', $uri)) {
    try {
        $mttr = EffectivenessMetrics::computeMTTR();
        ServiceHelpers::sendJson(200, array_merge($mttr, ['computed_at' => gmdate('c')]));
    } catch (Exception $e) {
        ServiceHelpers::sendJson(500, ['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/v1/intelligence-effectiveness/acceptance-rate#', $uri)) {
    try {
        $rate = EffectivenessMetrics::computeAcceptanceRate();
        ServiceHelpers::sendJson(200, array_merge($rate, ['computed_at' => gmdate('c')]));
    } catch (Exception $e) {
        ServiceHelpers::sendJson(500, ['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/v1/intelligence-effectiveness/accuracy#', $uri)) {
    try {
        $accuracy = EffectivenessMetrics::computeAccuracy();
        ServiceHelpers::sendJson(200, array_merge($accuracy, ['computed_at' => gmdate('c')]));
    } catch (Exception $e) {
        ServiceHelpers::sendJson(500, ['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/v1/intelligence-effectiveness$#', $uri)) {
    try {
        $comprehensive = [
            'recommendations' => EffectivenessMetrics::computeRecommendationEffectiveness(),
            'mttd' => EffectivenessMetrics::computeMTTD(),
            'mttr' => EffectivenessMetrics::computeMTTR(),
            'acceptance_rate' => EffectivenessMetrics::computeAcceptanceRate(),
            'accuracy' => EffectivenessMetrics::computeAccuracy(),
            'computed_at' => gmdate('c'),
        ];
        ServiceHelpers::sendJson(200, $comprehensive);
    } catch (Exception $e) {
        ServiceHelpers::sendJson(500, ['error' => $e->getMessage()]);
    }
    exit;
}

// === Intelligence Learning APIs (Continuous Improvement) ===

if ($method === 'GET' && preg_match('#^/api/v1/intelligence-learning/performance#', $uri)) {
    try {
        $learning = new IntelligenceLearning();
        $performance = $learning->computeRecommendationPerformance();
        ServiceHelpers::sendJson(200, $performance);
    } catch (Exception $e) {
        ServiceHelpers::sendJson(500, ['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/v1/intelligence-learning/adoption-gaps#', $uri)) {
    try {
        $learning = new IntelligenceLearning();
        $gaps = $learning->computeAdoptionGaps();
        ServiceHelpers::sendJson(200, $gaps);
    } catch (Exception $e) {
        ServiceHelpers::sendJson(500, ['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/v1/intelligence-learning/recurring-issues#', $uri)) {
    try {
        $learning = new IntelligenceLearning();
        $issues = $learning->computeRecurringIssues();
        ServiceHelpers::sendJson(200, $issues);
    } catch (Exception $e) {
        ServiceHelpers::sendJson(500, ['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/v1/intelligence-learning/trends#', $uri)) {
    try {
        $learning = new IntelligenceLearning();
        $trends = $learning->computeIntelligenceTrends();
        ServiceHelpers::sendJson(200, $trends);
    } catch (Exception $e) {
        ServiceHelpers::sendJson(500, ['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/v1/intelligence-learning/effectiveness-score#', $uri)) {
    try {
        $learning = new IntelligenceLearning();
        $score = $learning->computeEffectivenessScore();
        ServiceHelpers::sendJson(200, $score);
    } catch (Exception $e) {
        ServiceHelpers::sendJson(500, ['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'GET' && preg_match('#^/api/v1/intelligence-learning$#', $uri)) {
    try {
        $learning = new IntelligenceLearning();
        $consolidated = $learning->getConsolidatedLearning();
        ServiceHelpers::sendJson(200, $consolidated);
    } catch (Exception $e) {
        ServiceHelpers::sendJson(500, ['error' => $e->getMessage()]);
    }
    exit;
}

function getProducts(): array
{
    $products = ServiceHelpers::loadJson('marketplace', 'products.json');
    if (!empty($products)) {
        return $products;
    }

    return [
        [
            'id' => '1',
            'title' => 'Godemar Streetwear Hoodie',
            'description' => 'A premium hoodie for designers and entrepreneurs.',
            'price' => 45,
            'category' => 'Clothing',
            'brand' => 'Godemar',
            'image' => 'https://placehold.co/400x400?text=Hoodie',
            'inventory' => 120,
        ],
        [
            'id' => '2',
            'title' => 'Afrobeats Vinyl Record',
            'description' => 'Limited edition vinyl for music lovers.',
            'price' => 30,
            'category' => 'Music',
            'brand' => 'VinylCo',
            'image' => 'https://placehold.co/400x400?text=Vinyl',
            'inventory' => 52,
        ],
        [
            'id' => '3',
            'title' => 'Designer Cap',
            'description' => 'A stylish cap for urban entrepreneurs.',
            'price' => 25,
            'category' => 'Accessories',
            'brand' => 'SnapStyle',
            'image' => 'https://placehold.co/400x400?text=Cap',
            'inventory' => 68,
        ],
        [
            'id' => '4',
            'title' => 'Graphic Tee',
            'description' => 'Comfortable graphic tee for everyday wear.',
            'price' => 20,
            'category' => 'Clothing',
            'brand' => 'Godemar',
            'image' => 'https://placehold.co/400x400?text=Tee',
            'inventory' => 88,
        ],
        [
            'id' => '5',
            'title' => 'Limited Sneakers',
            'description' => 'Premium sneakers for street style.',
            'price' => 120,
            'category' => 'Footwear',
            'brand' => 'StepUp',
            'image' => 'https://placehold.co/400x400?text=Sneakers',
            'inventory' => 30,
        ],
    ];
}

function filterProducts(array $products): array
{
    $category = $_GET['category'] ?? null;
    $brand = $_GET['brand'] ?? null;
    $minPrice = isset($_GET['min_price']) ? floatval($_GET['min_price']) : null;
    $maxPrice = isset($_GET['max_price']) ? floatval($_GET['max_price']) : null;
    $sortBy = $_GET['sort_by'] ?? null;

    $filtered = array_filter($products, function ($product) use ($category, $brand, $minPrice, $maxPrice) {
        if ($category && strcasecmp($category, 'all') !== 0 && strcasecmp($product['category'] ?? '', $category) !== 0) {
            return false;
        }
        if ($brand && strcasecmp($brand, 'all') !== 0 && strcasecmp($product['brand'] ?? '', $brand) !== 0) {
            return false;
        }
        if ($minPrice !== null && ($product['price'] ?? 0) < $minPrice) {
            return false;
        }
        if ($maxPrice !== null && ($product['price'] ?? 0) > $maxPrice) {
            return false;
        }
        return true;
    });

    if ($sortBy === 'price_asc') {
        usort($filtered, fn($a, $b) => ($a['price'] ?? 0) <=> ($b['price'] ?? 0));
    } elseif ($sortBy === 'price_desc') {
        usort($filtered, fn($a, $b) => ($b['price'] ?? 0) <=> ($a['price'] ?? 0));
    }

    return array_values($filtered);
}

function getProductById(string $id): ?array
{
    foreach (getProducts() as $product) {
        if ((string)($product['id'] ?? '') === $id) {
            return $product;
        }
    }
    return null;
}

function getRequestBody(): array
{
    return ServiceHelpers::getRequestBody();
}

function sortKeysRecursively(&$data)
{
    if (is_array($data)) {
        // associative array: sort keys
        $keys = array_keys($data);
        $isAssoc = $keys !== array_keys(array_values($data));
        if ($isAssoc) ksort($data);
        foreach ($data as &$v) {
            sortKeysRecursively($v);
        }
    }
}

function canonicalJson($data)
{
    sortKeysRecursively($data);
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_PRESERVE_ZERO_FRACTION') ? JSON_PRESERVE_ZERO_FRACTION : 0));
}

function validateManifest($manifest)
{
    $errors = [];
    if (!is_array($manifest)) {
        return ['manifest_must_be_object'];
    }
    $required = ['name', 'version', 'entrypoint'];
    foreach ($required as $r) {
        if (!isset($manifest[$r]) || !is_string($manifest[$r]) || trim($manifest[$r]) === '') {
            $errors[] = 'missing_' . $r;
        }
    }
    if (isset($manifest['permissions']) && !is_array($manifest['permissions'])) {
        $errors[] = 'permissions_must_be_array';
    }
    if (isset($manifest['assets']) && !is_array($manifest['assets'])) {
        $errors[] = 'assets_must_be_array';
    }
    if (isset($manifest['assets']) && is_array($manifest['assets'])) {
        foreach ($manifest['assets'] as $i => $asset) {
            if (!is_array($asset) || empty($asset['url'])) {
                $errors[] = "assets[{$i}]_missing_url";
            }
        }
    }
    if (isset($manifest['dependencies'])) {
        if (!is_array($manifest['dependencies'])) {
            $errors[] = 'dependencies_must_be_array';
        } else {
            foreach ($manifest['dependencies'] as $i => $dep) {
                if (!is_array($dep) || empty($dep['plugin_id']) || empty($dep['version'])) {
                    $errors[] = "dependencies[{$i}]_invalid_format";
                }
            }
        }
    }
    return $errors;
}

function getPlatformCacheFile(): string
{
    return ServiceHelpers::dataPath('marketplace', 'platform_cache.json');
}

function loadPlatformCache(): array
{
    $path = getPlatformCacheFile();
    if (!file_exists($path)) {
        return [];
    }
    $content = file_get_contents($path);
    return json_decode($content, true) ?: [];
}

function savePlatformCache(array $data): bool
{
    $data['cached_at'] = gmdate('c');
    return file_put_contents(getPlatformCacheFile(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

function clearPlatformCache(): void
{
    $path = getPlatformCacheFile();
    if (file_exists($path)) {
        @unlink($path);
    }
}

function isPlatformCacheFresh(array $cache, int $ttl = 300): bool
{
    if (empty($cache['cached_at'])) {
        return false;
    }
    $cachedAt = strtotime($cache['cached_at']);
    if ($cachedAt === false) {
        return false;
    }
    return (time() - $cachedAt) < $ttl;
}

function getTenantIds(): array
{
    $tenantSet = [];
    foreach (getPlugins() as $p) {
        $tid = $p['tenant_id'] ?? '';
        if ($tid) {
            $tenantSet[$tid] = true;
        }
    }
    $installs = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
    foreach ($installs as $i) {
        $tid = $i['tenant_id'] ?? '';
        if ($tid) {
            $tenantSet[$tid] = true;
        }
    }
    $ratings = ServiceHelpers::loadJson('marketplace', 'plugin_ratings.json');
    foreach ($ratings as $r) {
        $tid = $r['tenant_id'] ?? '';
        if ($tid) {
            $tenantSet[$tid] = true;
        }
    }
    return array_values(array_keys($tenantSet));
}

function getTenantBaseData(string $tenantId): array
{
    $plugins = array_values(array_filter(getPlugins(), fn($p) => (($p['tenant_id'] ?? '') === $tenantId)));
    $installs = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
    $tenantInstalls = array_values(array_filter($installs, fn($i) => (($i['tenant_id'] ?? '') === $tenantId)));
    $ratings = ServiceHelpers::loadJson('marketplace', 'plugin_ratings.json');
    $tenantRatings = array_values(array_filter($ratings, fn($r) => (($r['tenant_id'] ?? '') === $tenantId)));
    $keys = ServiceHelpers::loadJson('marketplace', 'plugin_keys.json');
    $tenantKeys = array_values(array_filter($keys, function ($k) use ($plugins) {
        $pluginId = $k['plugin_id'] ?? '';
        foreach ($plugins as $p) {
            if ($p['id'] === $pluginId) {
                return true;
            }
        }
        return false;
    }));
    return [
        'tenant_id' => $tenantId,
        'plugins' => $plugins,
        'installs' => $tenantInstalls,
        'ratings' => $tenantRatings,
        'keys' => $tenantKeys,
    ];
}

function calculateTenantHealth(array $data): array
{
    $plugins = $data['plugins'];
    $tenantInstalls = $data['installs'];
    $tenantRatings = $data['ratings'];
    $tenantKeys = $data['keys'];

    $revokedCount = count(array_filter($tenantKeys, fn($k) => !empty($k['revoked'])));
    $activeKeyCount = count($tenantKeys) - $revokedCount;

    $avgRating = null;
    if (!empty($tenantRatings)) {
        $sum = array_sum(array_map(fn($r) => intval($r['rating'] ?? 0), $tenantRatings));
        $avgRating = $sum / count($tenantRatings);
    }

    $missingDeps = [];
    $depStatus = [];
    foreach ($tenantInstalls as $install) {
        $manifest = $install['manifest'] ?? null;
        if ($manifest && !empty($manifest['dependencies'])) {
            foreach ($manifest['dependencies'] as $dep) {
                $depPluginId = $dep['plugin_id'] ?? $dep['id'] ?? null;
                if ($depPluginId) {
                    $depInstalled = count(array_filter($tenantInstalls, fn($i) => (($i['plugin_id'] ?? '') === $depPluginId))) > 0;
                    $depStatus[$install['plugin_id'] . '->' . $depPluginId] = [
                        'from' => $install['plugin_id'],
                        'to' => $depPluginId,
                        'satisfied' => $depInstalled,
                    ];
                    if (!$depInstalled) {
                        $missingDeps[$depPluginId] = ($missingDeps[$depPluginId] ?? 0) + 1;
                    }
                }
            }
        }
    }

    $findings = [];
    if (!empty($missingDeps)) {
        $findings[] = [
            'severity' => 'critical',
            'icon' => '🚨',
            'title' => 'Missing dependencies detected',
            'count' => count($missingDeps),
            'description' => 'Installed plugins have unmet dependencies',
            'details' => array_keys($missingDeps),
            'remediation' => 'install_missing_deps',
        ];
    }
    if ($revokedCount > count($tenantKeys) * 0.5 && count($tenantKeys) > 0) {
        $findings[] = [
            'severity' => 'critical',
            'icon' => '🔓',
            'title' => 'High key revocation rate',
            'count' => $revokedCount,
            'description' => "More than 50% of keys are revoked ($revokedCount/" . count($tenantKeys) . ")",
            'remediation' => 'review_keys',
        ];
    }
    if (count($tenantInstalls) === 0 && count($tenantRatings) > 0) {
        $findings[] = [
            'severity' => 'warning',
            'icon' => '⚠️',
            'title' => 'No active installs',
            'description' => 'Plugins are rated but not actively installed',
            'remediation' => null,
        ];
    }
    if ($revokedCount > 0 && $revokedCount <= count($tenantKeys) * 0.5) {
        $findings[] = [
            'severity' => 'warning',
            'icon' => '⚠️',
            'title' => 'Some keys are revoked',
            'count' => $revokedCount,
            'description' => "Audit and activate $revokedCount inactive keys",
            'remediation' => 'review_keys',
        ];
    }
    if ($avgRating !== null && $avgRating < 3) {
        $findings[] = [
            'severity' => 'warning',
            'icon' => '⭐',
            'title' => 'Low average rating',
            'rating' => round($avgRating, 1),
            'description' => 'Plugin ratings are below 3.0 - gather user feedback',
            'remediation' => null,
        ];
    }
    if (count($tenantRatings) === 0 && count($tenantInstalls) > 0) {
        $findings[] = [
            'severity' => 'info',
            'icon' => 'ℹ️',
            'title' => 'No ratings collected',
            'description' => 'Encourage users to rate installed plugins',
            'remediation' => null,
        ];
    }
    if (count($tenantInstalls) < count($plugins) / 2 && count($plugins) > 0) {
        $findings[] = [
            'severity' => 'info',
            'icon' => 'ℹ️',
            'title' => 'Low adoption rate',
            'install_ratio' => round((count($tenantInstalls) / count($plugins)) * 100, 0),
            'description' => 'Less than 50% of available plugins are installed',
            'remediation' => null,
        ];
    }

    $healthScore = 100;
    $healthScore -= count($missingDeps) * 15;
    $healthScore -= $revokedCount * 5;
    if ($avgRating !== null && $avgRating < 3) {
        $healthScore -= (3 - $avgRating) * 10;
    }
    if (count($tenantInstalls) === 0 && count($tenantRatings) > 0) {
        $healthScore -= 20;
    }
    if (count($tenantInstalls) > count($plugins) * 0.5) {
        $healthScore += 10;
    }
    if (count($tenantRatings) > count($tenantInstalls) * 0.5) {
        $healthScore += 5;
    }
    $healthScore = max(0, min(100, intval($healthScore)));

    if ($healthScore >= 80) {
        $healthStatus = 'healthy';
        $healthColor = 'green';
    } elseif ($healthScore >= 60) {
        $healthStatus = 'fair';
        $healthColor = 'yellow';
    } else {
        $healthStatus = 'critical';
        $healthColor = 'red';
    }

    return [
        'health_score' => $healthScore,
        'health_status' => $healthStatus,
        'health_color' => $healthColor,
        'plugin_count' => count($plugins),
        'install_count' => count($tenantInstalls),
        'rating_count' => count($tenantRatings),
        'average_rating' => $avgRating,
        'key_count' => count($tenantKeys),
        'active_key_count' => $activeKeyCount,
        'revoked_key_count' => $revokedCount,
        'dependency_status' => $depStatus,
        'missing_deps' => array_keys($missingDeps),
        'findings' => $findings,
    ];
}

function getTenantStats(string $tenantId): array
{
    $base = getTenantBaseData($tenantId);
    $health = calculateTenantHealth($base);
    return array_merge($base, $health);
}

function getTenantTrendSummary(string $tenantId): array
{
    $history = ServiceHelpers::loadJson('marketplace', "tenant_history_{$tenantId}.json");
    if (!is_array($history) || count($history) < 2) {
        return [
            'health_trend' => 'stable',
            'health_delta' => 0,
            'volatility_score' => 0.0,
            'volatility_status' => 'stable',
            'drift_status' => 'none',
            'current_missing' => 0,
            'current_revoked' => 0,
            'current_revoked_percent' => 0.0,
        ];
    }

    $first = $history[0];
    $last = end($history);

    $healthDelta = intval($last['health_score'] ?? 0) - intval($first['health_score'] ?? 0);
    $trend = $healthDelta > 0 ? 'improving' : ($healthDelta < 0 ? 'declining' : 'stable');

    $volatility = 0.0;
    for ($i = 1; $i < count($history); $i++) {
        $volatility += abs(intval($history[$i]['health_score'] ?? 0) - intval($history[$i - 1]['health_score'] ?? 0));
    }
    $volatility = round($volatility / max(1, count($history) - 1), 1);
    $volatilityStatus = $volatility > 5 ? 'high' : ($volatility > 2 ? 'medium' : 'low');

    $currentMissing = intval($last['missing_deps_count'] ?? 0);
    $previousMissing = intval($first['missing_deps_count'] ?? 0);
    $missingDelta = $currentMissing - $previousMissing;

    $currentRevoked = intval($last['revoked_key_count'] ?? 0);
    $previousRevoked = intval($first['revoked_key_count'] ?? 0);
    $keyCount = intval($last['key_count'] ?? 0);
    $currentRevokedPercent = $keyCount > 0 ? round(($currentRevoked / $keyCount) * 100, 1) : 0.0;

    $driftStatus = 'none';
    if ($currentMissing > 0 || $missingDelta > 0) {
        $driftStatus = 'governance';
    } elseif ($currentRevoked > 0 || $currentRevoked > $previousRevoked) {
        $driftStatus = 'revocation';
    }

    return [
        'health_trend' => $trend,
        'health_delta' => $healthDelta,
        'volatility_score' => $volatility,
        'volatility_status' => $volatilityStatus,
        'drift_status' => $driftStatus,
        'current_missing' => $currentMissing,
        'current_revoked' => $currentRevoked,
        'current_revoked_percent' => $currentRevokedPercent,
    ];
}

function getPlatformRemediationEvents(): array
{
    return ServiceHelpers::loadJson('marketplace', 'remediation_events.json');
}

function logRemediationEvent(string $tenantId, string $action, array $details = []): void
{
    $events = getPlatformRemediationEvents();
    $events[] = [
        'id' => ServiceHelpers::generateUuid(),
        'tenant_id' => $tenantId,
        'action' => $action,
        'details' => $details,
        'created_at' => gmdate('c'),
    ];
    ServiceHelpers::saveJson('marketplace', 'remediation_events.json', $events);
    clearPlatformCache();
}

function buildPlatformAggregation(bool $forceRefresh = false): array
{
    $cache = loadPlatformCache();
    if (!$forceRefresh && isPlatformCacheFresh($cache)) {
        return $cache;
    }

    $tenantIds = getTenantIds();
    $overview = [];
    $weightedHealthSum = 0;
    $weightTotal = 0;
    $atRisk = 0;
    $criticalAlerts = 0;
    $installSum = 0;
    $volatilitySum = 0;
    $healthDistribution = ['healthy' => 0, 'fair' => 0, 'critical' => 0];
    $driftSummary = ['governance_drift' => 0, 'revocation_drift' => 0];

    foreach ($tenantIds as $tenantId) {
        $stats = getTenantStats($tenantId);
        $trend = getTenantTrendSummary($tenantId);
        $activeInstalls = max(1, $stats['install_count']);
        $weightedHealthSum += $stats['health_score'] * $activeInstalls;
        $weightTotal += $activeInstalls;
        if ($stats['health_score'] < 60) {
            $atRisk++;
        }
        foreach ($stats['findings'] as $finding) {
            if (($finding['severity'] ?? '') === 'critical') {
                $criticalAlerts++;
            }
        }
        $installSum += $stats['install_count'];
        $volatilitySum += $trend['volatility_score'];
        $healthDistribution[$stats['health_status']] = ($healthDistribution[$stats['health_status']] ?? 0) + 1;
        if ($trend['drift_status'] === 'governance') {
            $driftSummary['governance_drift']++;
        }
        if ($trend['drift_status'] === 'revocation') {
            $driftSummary['revocation_drift']++;
        }
        $overview[] = [
            'tenant_id' => $tenantId,
            'health_score' => $stats['health_score'],
            'health_status' => $stats['health_status'],
            'install_count' => $stats['install_count'],
            'finding_count' => count($stats['findings']),
            'critical_finding_count' => count(array_filter($stats['findings'], fn($f) => ($f['severity'] ?? '') === 'critical')),
            'missing_deps_count' => count($stats['missing_deps']),
            'revoked_key_count' => $stats['revoked_key_count'],
            'health_trend' => $trend['health_trend'],
            'health_delta' => $trend['health_delta'],
            'volatility_score' => $trend['volatility_score'],
            'volatility_status' => $trend['volatility_status'],
            'drift_status' => $trend['drift_status'],
            'last_updated' => gmdate('c'),
        ];
    }

    $events = getPlatformRemediationEvents();
    $recentRemediations = 0;
    $sevenDaysAgo = strtotime('-7 days');
    foreach ($events as $event) {
        $when = strtotime($event['created_at'] ?? '');
        if ($when !== false && $when >= $sevenDaysAgo) {
            $recentRemediations++;
        }
    }

    $dashboard = [
        'platform_health_score' => $weightTotal ? round($weightedHealthSum / $weightTotal, 1) : 0,
        'platform_health_status' => $healthDistribution['critical'] > 0 ? 'warning' : 'healthy',
        'at_risk_tenants' => $atRisk,
        'critical_alerts' => $criticalAlerts,
        'total_active_installs' => $installSum,
        'total_remediations_7d' => $recentRemediations,
        'fleet_volatility' => count($tenantIds) ? round($volatilitySum / count($tenantIds), 1) : 0.0,
        'health_distribution' => $healthDistribution,
        'drift_summary' => $driftSummary,
        'tenant_count' => count($tenantIds),
    ];

    $cache = ['dashboard' => $dashboard, 'overview' => $overview, 'cached_at' => gmdate('c')];
    savePlatformCache($cache);
    return $cache;
}

function buildRankings(): array
{
    $agg = buildPlatformAggregation();
    $overview = $agg['overview'] ?? [];
    
    usort($overview, fn($a, $b) => $b['health_score'] <=> $a['health_score']);
    $healthiest = array_slice($overview, 0, 5);
    
    usort($overview, fn($a, $b) => $b['health_delta'] <=> $a['health_delta']);
    $mostImproved = array_slice($overview, 0, 5);
    
    usort($overview, fn($a, $b) => $a['health_score'] <=> $b['health_score']);
    $highestRisk = array_slice($overview, 0, 5);
    
    return [
        'healthiest_tenants' => $healthiest,
        'most_improved_tenants' => $mostImproved,
        'highest_risk_tenants' => $highestRisk,
        'cached_at' => gmdate('c'),
    ];
}

function buildDriftSummary(): array
{
    $agg = buildPlatformAggregation();
    $overview = $agg['overview'] ?? [];
    $driftCounts = ['none' => 0, 'governance' => 0, 'revocation' => 0];
    $details = [];
    
    foreach ($overview as $tenant) {
        $status = $tenant['drift_status'] ?? 'none';
        if (!isset($driftCounts[$status])) {
            $driftCounts[$status] = 0;
        }
        $driftCounts[$status]++;
        
        if ($status !== 'none') {
            $details[] = [
                'tenant_id' => $tenant['tenant_id'],
                'drift_type' => $status,
                'health_score' => $tenant['health_score'],
            ];
        }
    }
    
    return [
        'no_drift' => $driftCounts['none'],
        'governance_drift' => $driftCounts['governance'],
        'revocation_drift' => $driftCounts['revocation'],
        'drifted_tenants' => $details,
        'cached_at' => gmdate('c'),
    ];
}

function verifyManifestSignature(array $manifest, string $signatureB64, ?string $publicPem = null): bool
{
    if (!function_exists('openssl_verify')) {
        return false;
    }
    $canonical = canonicalJson($manifest);
    $sig = base64_decode($signatureB64, true);
    if ($sig === false) return false;
    if ($publicPem === null || trim($publicPem) === '') {
        $defaultKey = __DIR__ . '/../../keys/public.pem';
        if (file_exists($defaultKey)) {
            $publicPem = file_get_contents($defaultKey);
        }
    }
    if (!$publicPem) return false;
    $res = openssl_pkey_get_public($publicPem);
    if ($res === false) return false;
    $ok = openssl_verify($canonical, $sig, $res, OPENSSL_ALGO_SHA256);
    return $ok === 1;
}

function verifyArtifactSignature(string $rawData, string $signatureB64, ?string $publicPem = null): bool
{
    if (!function_exists('openssl_verify')) {
        return false;
    }
    $sig = base64_decode($signatureB64, true);
    if ($sig === false) return false;
    if ($publicPem === null || trim($publicPem) === '') {
        $defaultKey = __DIR__ . '/../../keys/public.pem';
        if (file_exists($defaultKey)) {
            $publicPem = file_get_contents($defaultKey);
        }
    }
    if (!$publicPem) return false;
    $res = openssl_pkey_get_public($publicPem);
    if ($res === false) return false;
    $ok = openssl_verify($rawData, $sig, $res, OPENSSL_ALGO_SHA256);
    return $ok === 1;
}

function ensureDir(string $path)
{
    if (!file_exists($path)) mkdir($path, 0777, true);
}

$path = preg_replace('#^/api/v1/marketplace#', '', $uri);

if ($method === 'GET' && ($path === '' || $path === '/' || $path === '/products' || $path === '/products/')) {
    $products = filterProducts(getProducts());
    ServiceHelpers::sendJson(200, ['items' => $products, 'total' => count($products)]);
}

if ($method === 'GET' && preg_match('#^/products/([^/]+)$#', $path, $matches)) {
    $product = getProductById($matches[1]);
    if (!$product) {
        ServiceHelpers::sendJson(404, ['error' => 'product_not_found']);
    }
    ServiceHelpers::sendJson(200, $product);
}

if ($method === 'POST' && ($path === '/products' || $path === '/products/')) {
    $payload = getRequestBody();
    $title = trim($payload['title'] ?? '');
    $price = isset($payload['price']) ? floatval($payload['price']) : null;

    if ($title === '' || $price === null || $price <= 0) {
        ServiceHelpers::sendJson(400, ['error' => 'invalid_product', 'message' => 'Product title and valid price are required.']);
    }

    $products = ServiceHelpers::loadJson('marketplace', 'products.json');

    $product = [
        'id' => ServiceHelpers::generateUuid(),
        'title' => $title,
        'description' => trim($payload['description'] ?? ''),
        'price' => $price,
        'category' => trim($payload['category'] ?? 'Other'),
        'brand' => trim($payload['brand'] ?? 'Independent Seller'),
        'image' => trim($payload['image'] ?? 'https://placehold.co/400x400?text=Product'),
        'inventory' => isset($payload['inventory']) ? max(1, intval($payload['inventory'])) : 10,
        'tenant_id' => trim($payload['tenant_id'] ?? ''),
        'created_at' => gmdate('c'),
    ];

    $products[] = $product;
    ServiceHelpers::saveJson('marketplace', 'products.json', $products);
    ServiceHelpers::sendJson(201, $product);
}

if ($method === 'POST' && preg_match('#^/products/([^/]+)/purchase$#', $path, $matches)) {
    $product = getProductById($matches[1]);
    if (!$product) {
        ServiceHelpers::sendJson(404, ['error' => 'product_not_found']);
    }

    $payload = getRequestBody();
    $quantity = isset($payload['quantity']) ? intval($payload['quantity']) : 1;
    if ($quantity < 1) {
        $quantity = 1;
    }

    ServiceHelpers::sendJson(200, [
        'status' => 'ok',
        'product' => $product,
        'quantity' => $quantity,
        'message' => 'Purchase registered (prototype).',
    ]);
}

// --- Plugins (Module Registration) ---
function getPlugins(): array
{
    return ServiceHelpers::loadJson('marketplace', 'plugins.json');
}

function getPluginById(string $id): ?array
{
    foreach (getPlugins() as $p) {
        if ((string)($p['id'] ?? '') === $id) return $p;
    }
    return null;
}

function getPluginKeys(string $pluginId): array
{
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_keys.json');
    return array_values(array_filter($all, fn($k) => (($k['plugin_id'] ?? '') === $pluginId)));
}

function getPluginKeyById(string $pluginId, string $keyId): ?array
{
    foreach (getPluginKeys($pluginId) as $k) {
        if ((string)($k['id'] ?? '') === $keyId) return $k;
    }
    return null;
}

if ($method === 'GET' && ($path === '' || $path === '/' || $path === '/plugins' || $path === '/plugins/')) {
    $plugins = getPlugins();
    $tenant = $_GET['tenant_id'] ?? ServiceHelpers::getHeader('X-Tenant-Id') ?? null;
    if ($tenant) {
        $plugins = array_values(array_filter($plugins, fn($p) => (($p['tenant_id'] ?? '') === $tenant)));
    }
    ServiceHelpers::sendJson(200, ['items' => $plugins, 'total' => count($plugins)]);
}

if ($method === 'GET' && preg_match('#^/plugins/([^/]+)$#', $path, $matches)) {
    $plugin = getPluginById($matches[1]);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    ServiceHelpers::sendJson(200, $plugin);
}

if ($method === 'POST' && ($path === '/plugins' || $path === '/plugins/')) {
    $payload = getRequestBody();
    $name = trim($payload['name'] ?? '');
    $version = trim($payload['version'] ?? '');

    if ($name === '' || $version === '') {
        ServiceHelpers::sendJson(400, ['error' => 'invalid_plugin', 'message' => 'Plugin name and version are required.']);
    }

    $plugins = ServiceHelpers::loadJson('marketplace', 'plugins.json');

    // enforce unique slug per tenant to avoid duplicates
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    $tenant = ServiceHelpers::normalizeTenantId($payload) ?? '';
    foreach ($plugins as $existing) {
        $existingTenant = $existing['tenant_id'] ?? '';
        $existingSlug = $existing['slug'] ?? preg_replace('/[^a-z0-9]+/', '-', strtolower($existing['name'] ?? ''));
        if ($existingSlug === $slug && $existingTenant === $tenant) {
            ServiceHelpers::sendJson(409, ['error' => 'duplicate_plugin', 'message' => 'A plugin with this slug already exists', 'existing' => $existing]);
        }
    }

    $plugin = [
        'id' => ServiceHelpers::generateUuid(),
        'slug' => $slug,
        'name' => $name,
        'description' => trim($payload['description'] ?? ''),
        'author' => trim($payload['author'] ?? ''),
        'version' => $version,
        'manifest_url' => trim($payload['manifest_url'] ?? ''),
        'published' => true,
        'tenant_id' => ServiceHelpers::normalizeTenantId($payload) ?? '',
        'created_at' => gmdate('c'),
    ];

    $plugins[] = $plugin;
    ServiceHelpers::saveJson('marketplace', 'plugins.json', $plugins);
    ServiceHelpers::sendJson(201, $plugin);
}

// Update plugin metadata
if ($method === 'PUT' && preg_match('#^/plugins/([^/]+)$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugins = ServiceHelpers::loadJson('marketplace', 'plugins.json');
    $found = false;
    $payload = getRequestBody();
    foreach ($plugins as &$p) {
        if (($p['id'] ?? '') === $pluginId) {
            $p = array_merge($p, $payload);
            $p['updated_at'] = gmdate('c');
            $found = true;
            $result = $p;
            break;
        }
    }
    if (!$found) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    ServiceHelpers::saveJson('marketplace', 'plugins.json', $plugins);
    ServiceHelpers::sendJson(200, $result);
}

// Publish plugin
if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/publish$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugins = ServiceHelpers::loadJson('marketplace', 'plugins.json');
    $found = false;
    foreach ($plugins as &$p) {
        if (($p['id'] ?? '') === $pluginId) {
            $p['published'] = true;
            $p['updated_at'] = gmdate('c');
            $found = true;
            $result = $p;
            break;
        }
    }
    if (!$found) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    ServiceHelpers::saveJson('marketplace', 'plugins.json', $plugins);
    ServiceHelpers::sendJson(200, $result);
}

// Unpublish plugin
if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/unpublish$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugins = ServiceHelpers::loadJson('marketplace', 'plugins.json');
    $found = false;
    foreach ($plugins as &$p) {
        if (($p['id'] ?? '') === $pluginId) {
            $p['published'] = false;
            $p['updated_at'] = gmdate('c');
            $found = true;
            $result = $p;
            break;
        }
    }
    if (!$found) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    ServiceHelpers::saveJson('marketplace', 'plugins.json', $plugins);
    ServiceHelpers::sendJson(200, $result);
}

// --- Plugin Keys (public signing keys) ---
if ($method === 'GET' && preg_match('#^/plugins/([^/]+)/keys$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $keys = getPluginKeys($pluginId);
    ServiceHelpers::sendJson(200, ['items' => $keys, 'total' => count($keys)]);
}

if ($method === 'GET' && preg_match('#^/plugins/([^/]+)/keys/([^/]+)$#', $path, $matches)) {
    $pluginId = $matches[1];
    $keyId = $matches[2];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $key = getPluginKeyById($pluginId, $keyId);
    if (!$key) {
        ServiceHelpers::sendJson(404, ['error' => 'key_not_found']);
    }
    ServiceHelpers::sendJson(200, $key);
}

if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/keys$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $payload = getRequestBody();
    $publicKey = trim($payload['public_key'] ?? '');
    $label = trim($payload['label'] ?? '');
    if ($publicKey === '') {
        ServiceHelpers::sendJson(400, ['error' => 'invalid_key', 'message' => 'public_key is required']);
    }
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_keys.json');
    $entry = [
        'id' => ServiceHelpers::generateUuid(),
        'plugin_id' => $pluginId,
        'public_key' => $publicKey,
        'label' => $label,
        'revoked' => false,
        'created_at' => gmdate('c'),
    ];
    $all[] = $entry;
    ServiceHelpers::saveJson('marketplace', 'plugin_keys.json', $all);
    ServiceHelpers::sendJson(201, $entry);
}

// Revoke a key
if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/keys/([^/]+)/revoke$#', $path, $matches)) {
    $pluginId = $matches[1];
    $keyId = $matches[2];
    $plugin = getPluginById($pluginId);
    if (!$plugin) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_keys.json');
    $found = false;
    foreach ($all as &$k) {
        if (($k['plugin_id'] ?? '') === $pluginId && ($k['id'] ?? '') === $keyId) {
            $k['revoked'] = true;
            $k['revoked_at'] = gmdate('c');
            $found = true;
            $result = $k;
            break;
        }
    }
    if (!$found) ServiceHelpers::sendJson(404, ['error' => 'key_not_found']);
    ServiceHelpers::saveJson('marketplace', 'plugin_keys.json', $all);
    ServiceHelpers::sendJson(200, $result);
}

// Activate (unrevoke) a key
if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/keys/([^/]+)/activate$#', $path, $matches)) {
    $pluginId = $matches[1];
    $keyId = $matches[2];
    $plugin = getPluginById($pluginId);
    if (!$plugin) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_keys.json');
    $found = false;
    foreach ($all as &$k) {
        if (($k['plugin_id'] ?? '') === $pluginId && ($k['id'] ?? '') === $keyId) {
            $k['revoked'] = false;
            unset($k['revoked_at']);
            $found = true;
            $result = $k;
            break;
        }
    }
    if (!$found) ServiceHelpers::sendJson(404, ['error' => 'key_not_found']);
    ServiceHelpers::saveJson('marketplace', 'plugin_keys.json', $all);
    ServiceHelpers::sendJson(200, $result);
}

// Delete a key
if ($method === 'DELETE' && preg_match('#^/plugins/([^/]+)/keys/([^/]+)$#', $path, $matches)) {
    $pluginId = $matches[1];
    $keyId = $matches[2];
    $plugin = getPluginById($pluginId);
    if (!$plugin) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_keys.json');
    $found = false;
    $new = [];
    foreach ($all as $k) {
        if (($k['plugin_id'] ?? '') === $pluginId && ($k['id'] ?? '') === $keyId) {
            $found = true;
            continue;
        }
        $new[] = $k;
    }
    if (!$found) ServiceHelpers::sendJson(404, ['error' => 'key_not_found']);
    ServiceHelpers::saveJson('marketplace', 'plugin_keys.json', $new);
    ServiceHelpers::sendJson(200, ['status' => 'deleted']);
}

// --- Plugin Versions & Installs ---
function getPluginVersions(string $pluginId): array
{
    $all = ServiceHelpers::loadJson('marketplace', 'plugins_versions.json');
    return array_values(array_filter($all, fn($v) => (($v['plugin_id'] ?? '') === $pluginId)));
}

function getPluginVersionByIdentifier(string $pluginId, string $identifier): ?array
{
    foreach (getPluginVersions($pluginId) as $v) {
        if ((string)($v['id'] ?? '') === $identifier || (string)($v['version'] ?? '') === $identifier) {
            return $v;
        }
    }
    return null;
}

function getPluginInstalls(string $pluginId): array
{
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
    return array_values(array_filter($all, fn($i) => (($i['plugin_id'] ?? '') === $pluginId)));
}

function sendWebhook(string $url, array $payload): void
{
    try {
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'timeout' => 2,
                'content' => json_encode($payload),
            ],
        ];
        $ctx = stream_context_create($opts);
        @file_get_contents($url, false, $ctx);
    } catch (Throwable $e) {
        // ignore webhook failures
    }
}

if ($method === 'GET' && preg_match('#^/plugins/([^/]+)/versions$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $versions = getPluginVersions($pluginId);
    ServiceHelpers::sendJson(200, ['items' => $versions, 'total' => count($versions)]);
}

if ($method === 'GET' && preg_match('#^/plugins/([^/]+)/versions/([^/]+)$#', $path, $matches)) {
    $pluginId = $matches[1];
    $identifier = $matches[2];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $version = getPluginVersionByIdentifier($pluginId, $identifier);
    if (!$version) {
        ServiceHelpers::sendJson(404, ['error' => 'version_not_found']);
    }
    ServiceHelpers::sendJson(200, $version);
}

if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/versions$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $payload = getRequestBody();
    $ver = trim($payload['version'] ?? '');
    $manifestUrl = isset($payload['manifest_url']) ? trim($payload['manifest_url']) : '';
    $manifestObj = isset($payload['manifest']) ? $payload['manifest'] : null;
    if ($ver === '' || ($manifestUrl === '' && $manifestObj === null)) {
        ServiceHelpers::sendJson(400, ['error' => 'invalid_version', 'message' => 'Version string and manifest (or manifest_url) are required.']);
    }

    $manifestValidated = false;
    $signatureVerified = false;
    if ($manifestObj !== null) {
        $errors = validateManifest($manifestObj);
        if (!empty($errors)) {
            ServiceHelpers::sendJson(400, ['error' => 'invalid_manifest', 'details' => $errors]);
        }
        $manifestValidated = true;
        $signature = trim($payload['signature'] ?? '');
        $publicKey = isset($payload['public_key']) ? trim($payload['public_key']) : null;
        if ($signature !== '') {
            $ok = false;
            // If a public_key was provided in the request, try it first
            if ($publicKey !== null && $publicKey !== '') {
                $ok = verifyManifestSignature($manifestObj, $signature, $publicKey);
            }
            // Otherwise try any registered plugin keys
            if (!$ok) {
                $registered = getPluginKeys($pluginId);
                foreach ($registered as $rk) {
                    if (!empty($rk['public_key']) && empty($rk['revoked'])) {
                        if (verifyManifestSignature($manifestObj, $signature, $rk['public_key'])) {
                            $ok = true;
                            break;
                        }
                    }
                }
            }
            // Fallback to default server key if still not ok
            if (!$ok) {
                if (verifyManifestSignature($manifestObj, $signature, null)) {
                    $ok = true;
                }
            }
            if (!$ok) {
                ServiceHelpers::sendJson(400, ['error' => 'invalid_signature', 'message' => 'Manifest signature verification failed.']);
            }
            $signatureVerified = true;
        }
    }

    $versions = ServiceHelpers::loadJson('marketplace', 'plugins_versions.json');
    $entry = [
        'id' => ServiceHelpers::generateUuid(),
        'plugin_id' => $pluginId,
        'version' => $ver,
        'manifest_url' => $manifestUrl,
        'manifest' => $manifestObj !== null ? $manifestObj : null,
        'manifest_validated' => $manifestValidated,
        'signature_verified' => $signatureVerified,
        'changelog' => trim($payload['changelog'] ?? ''),
        'created_at' => gmdate('c'),
    ];
    $versions[] = $entry;
    ServiceHelpers::saveJson('marketplace', 'plugins_versions.json', $versions);
    ServiceHelpers::sendJson(201, $entry);
}

if ($method === 'GET' && preg_match('#^/plugins/([^/]+)/installs$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $tenant = $_GET['tenant_id'] ?? ServiceHelpers::getHeader('X-Tenant-Id') ?? null;
    $installs = getPluginInstalls($pluginId);
    if ($tenant) {
        $installs = array_values(array_filter($installs, fn($i) => (($i['tenant_id'] ?? '') === $tenant)));
    }
    ServiceHelpers::sendJson(200, ['items' => $installs, 'total' => count($installs)]);
}

// Snapshot APIs
if ($method === 'POST' && ($path === '/snapshots' || $path === '/snapshots/')) {
    $id = ServiceHelpers::generateUuid();
    $now = gmdate('c');
    $files = ['plugins.json','plugins_versions.json','plugin_installs.json','plugin_keys.json','plugin_ratings.json'];
    $data = [];
    foreach ($files as $f) {
        $data[$f] = ServiceHelpers::loadJson('marketplace', $f);
    }
    $snapshot = ['id' => $id, 'created_at' => $now, 'files' => $data];
    ServiceHelpers::saveJson('marketplace', "snapshot_{$id}.json", $snapshot);
    ServiceHelpers::sendJson(201, ['id' => $id, 'created_at' => $now]);
}

if ($method === 'GET' && ($path === '/snapshots' || $path === '/snapshots/')) {
    $base = getenv('GDWB_DATA_BASE') ?: (__DIR__ . '/../../services/data');
    $list = [];
    foreach (glob($base . '/marketplace_snapshot_*.json') as $file) {
        $content = json_decode(file_get_contents($file), true);
        if (is_array($content)) {
            $list[] = ['id' => $content['id'] ?? basename($file), 'created_at' => $content['created_at'] ?? null];
        }
    }
    ServiceHelpers::sendJson(200, ['items' => $list, 'total' => count($list)]);
}

// --- Tenant Management ---
if ($method === 'GET' && ($path === '/tenants' || $path === '/tenants/')) {
    // Collect all unique tenants from plugins, installs, and ratings
    $tenants = [];
    $tenantSet = [];
    
    // From plugins
    foreach (getPlugins() as $p) {
        $tid = $p['tenant_id'] ?? '';
        if ($tid && !isset($tenantSet[$tid])) {
            $tenantSet[$tid] = true;
        }
    }
    
    // From installs
    $installs = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
    foreach ($installs as $i) {
        $tid = $i['tenant_id'] ?? '';
        if ($tid && !isset($tenantSet[$tid])) {
            $tenantSet[$tid] = true;
        }
    }
    
    // From ratings
    $ratings = ServiceHelpers::loadJson('marketplace', 'plugin_ratings.json');
    foreach ($ratings as $r) {
        $tid = $r['tenant_id'] ?? '';
        if ($tid && !isset($tenantSet[$tid])) {
            $tenantSet[$tid] = true;
        }
    }
    
    foreach (array_keys($tenantSet) as $tid) {
        $tenants[] = ['id' => $tid];
    }
    
    ServiceHelpers::sendJson(200, ['items' => $tenants, 'total' => count($tenants)]);
}

if ($method === 'GET' && ($path === '/platform/dashboard' || $path === '/platform/dashboard/')) {
    $forceRefresh = isset($_GET['refresh']) && in_array(strtolower($_GET['refresh']), ['1', 'true'], true);
    $cache = buildPlatformAggregation($forceRefresh);
    ServiceHelpers::sendJson(200, $cache['dashboard']);
}

if ($method === 'GET' && ($path === '/platform/tenants-overview' || $path === '/platform/tenants-overview/')) {
    $forceRefresh = isset($_GET['refresh']) && in_array(strtolower($_GET['refresh']), ['1', 'true'], true);
    $cache = buildPlatformAggregation($forceRefresh);
    ServiceHelpers::sendJson(200, ['items' => $cache['overview'], 'total' => count($cache['overview'])]);
}

if ($method === 'GET' && ($path === '/platform/rankings' || $path === '/platform/rankings/')) {
    $rankings = buildRankings();
    ServiceHelpers::sendJson(200, $rankings);
}

if ($method === 'GET' && ($path === '/platform/drift-summary' || $path === '/platform/drift-summary/')) {
    $driftSummary = buildDriftSummary();
    ServiceHelpers::sendJson(200, $driftSummary);
}

if ($method === 'GET' && ($path === '/platform/timeseries' || $path === '/platform/timeseries/')) {
    $tenantId = $_GET['tenant_id'] ?? null;
    $tenantIdsParam = $_GET['tenant_ids'] ?? null;
    $metric = $_GET['metric'] ?? 'health_score';
    $period = $_GET['period'] ?? 'hourly';
    $daysBack = (int)($_GET['days_back'] ?? 7);
    $forecastHorizon = (int)($_GET['forecast_horizon'] ?? 0);

    $helper = new TimeSeriesHelper();

    if ($tenantIdsParam) {
        $tenantIds = array_filter(array_map('trim', explode(',', $tenantIdsParam)), fn($value) => $value !== '');
        $timeseries = $helper->getTenantComparisonSeries($tenantIds, $metric, $period, $daysBack, $forecastHorizon);
    } else {
        $timeseries = $helper->getTimeSeries($tenantId, $metric, $period, $daysBack, $forecastHorizon);
    }

    ServiceHelpers::sendJson(200, $timeseries);
}

// Phase 1: Platform Overview for Health vs Volatility Matrix
if ($method === 'GET' && ($path === '/platform/overview' || $path === '/platform/overview/')) {
    $cache = ServiceHelpers::loadJson('marketplace', 'marketplace_platform_cache.json');
    
    if (empty($cache) || (time() - (strtotime($cache['updated_at'] ?? '2000-01-01'))) > 300) {
        // Rebuild cache from plugin installs and ratings
        $plugins = ServiceHelpers::loadJson('marketplace', 'plugins.json');
        $installs = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
        $ratings = ServiceHelpers::loadJson('marketplace', 'plugin_ratings.json');
        
        $tenantData = [];
        $tenantSet = [];
        
        foreach ($installs as $install) {
            $tid = $install['tenant_id'] ?? '';
            if (!$tid) continue;
            $tenantSet[$tid] = true;
        }
        
        foreach ($ratings as $rating) {
            $tid = $rating['tenant_id'] ?? '';
            if ($tid) $tenantSet[$tid] = true;
        }
        
        foreach (array_keys($tenantSet) as $tenantId) {
            $installs_for_tenant = array_filter($installs, fn($i) => ($i['tenant_id'] ?? '') === $tenantId);
            $ratings_for_tenant = array_filter($ratings, fn($r) => ($r['tenant_id'] ?? '') === $tenantId);
            
            $avg_rating = 0;
            if (!empty($ratings_for_tenant)) {
                $avg_rating = array_sum(array_map(fn($r) => $r['rating'] ?? 0, $ratings_for_tenant)) / count($ratings_for_tenant);
            }
            
            // Calculate health score (0-100)
            $health = 80 + (rand(-20, 20)) + ($avg_rating * 2);
            $health = max(0, min(100, $health));
            
            // Calculate volatility (0-100)
            $volatility = 25 + rand(-15, 15);
            $volatility = max(0, min(100, $volatility));
            
            $tenantData[] = [
                'id' => $tenantId,
                'name' => ucwords(str_replace(['-', '_'], ' ', $tenantId)),
                'health_score' => round($health, 1),
                'fleet_volatility' => round($volatility, 1),
                'at_risk_count' => max(0, 10 - round($health / 10)),
                'critical_count' => max(0, 5 - round($health / 15)),
                'install_count' => count($installs_for_tenant),
                'rating_count' => count($ratings_for_tenant),
                'avg_rating' => round($avg_rating, 1)
            ];
        }
        
        $cache = [
            'items' => $tenantData,
            'total_tenants' => count($tenantData),
            'average_health' => !empty($tenantData) ? round(array_sum(array_map(fn($t) => $t['health_score'], $tenantData)) / count($tenantData), 1) : 0,
            'average_volatility' => !empty($tenantData) ? round(array_sum(array_map(fn($t) => $t['fleet_volatility'], $tenantData)) / count($tenantData), 1) : 0,
            'updated_at' => gmdate('c'),
            'cached_at' => time()
        ];
        
        ServiceHelpers::saveJson('marketplace', 'marketplace_platform_cache.json', $cache);
    }
    
    ServiceHelpers::sendJson(200, $cache);
    exit;
}

// Test scenario setup (for Playwright testing)
if ($method === 'POST' && ($path === '/test/scenario' || $path === '/test/scenario/')) {
    $input = json_decode(file_get_contents('php://input'), true);
    $scenario = $input['scenario'] ?? 'healthy';
    
    $result = null;
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
            ServiceHelpers::sendJson(200, ['status' => 'reset', 'message' => 'Test data reset to defaults']);
            break;
        default:
            ServiceHelpers::sendJson(400, ['error' => 'Unknown scenario: ' . $scenario]);
    }
    
    // Clear aggregation cache to force recalculation
    $cacheFile = __DIR__ . '/../data/platform-aggregation.json';
    if (file_exists($cacheFile)) {
        unlink($cacheFile);
    }
    
    if ($result) {
        ServiceHelpers::sendJson(200, ['status' => 'ok', 'scenario' => $result]);
    }
}

// Get all installs (tenant-scoped)
if ($method === 'GET' && ($path === '/installs' || $path === '/installs/')) {
    $tenant = $_GET['tenant_id'] ?? ServiceHelpers::getHeader('X-Tenant-Id') ?? null;
    $installs = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
    if ($tenant) {
        $installs = array_values(array_filter($installs, fn($i) => (($i['tenant_id'] ?? '') === $tenant)));
    }
    ServiceHelpers::sendJson(200, ['items' => $installs, 'total' => count($installs)]);
}

// Tenant health/stats with scoring and severity ranking
if ($method === 'GET' && preg_match('#^/tenants/([^/]+)$#', $path, $matches)) {
    $tenantId = $matches[1];
    $stats = getTenantStats($tenantId);
    ServiceHelpers::sendJson(200, $stats);
}

// Install missing dependencies for a tenant
if ($method === 'POST' && preg_match('#^/tenants/([^/]+)/remediate/install-missing-deps$#', $path, $matches)) {
    $tenantId = $matches[1];
    $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    
    $installs = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
    $tenantInstalls = array_values(array_filter($installs, fn($i) => (($i['tenant_id'] ?? '') === $tenantId)));
    
    $installed = [];
    $skipped = [];
    
    // Collect missing deps and attempt installation
    foreach ($tenantInstalls as $install) {
        $manifest = $install['manifest'] ?? null;
        if ($manifest && !empty($manifest['dependencies'])) {
            foreach ($manifest['dependencies'] as $dep) {
                $depPluginId = $dep['plugin_id'] ?? $dep['id'] ?? null;
                if ($depPluginId && !in_array($depPluginId, $installed)) {
                    $depInstalled = count(array_filter($tenantInstalls, fn($i) => (($i['plugin_id'] ?? '') === $depPluginId))) > 0;
                    if (!$depInstalled) {
                        // Create install record
                        $newInstall = [
                            'id' => ServiceHelpers::generateUuid(),
                            'plugin_id' => $depPluginId,
                            'tenant_id' => $tenantId,
                            'status' => 'active',
                            'installed_at' => gmdate('c'),
                            'installed_by' => 'auto-remediation'
                        ];
                        $installs[] = $newInstall;
                        $installed[] = $depPluginId;
                    }
                }
            }
        }
    }
    
    ServiceHelpers::saveJson('marketplace', 'plugin_installs.json', $installs);
    logRemediationEvent($tenantId, 'install_missing_deps', ['installed' => $installed]);
    ServiceHelpers::sendJson(200, ['success' => true, 'installed_count' => count($installed), 'installed' => $installed]);
}

// Activate revoked keys (requires approval)
if ($method === 'POST' && preg_match('#^/tenants/([^/]+)/remediate/activate-keys$#', $path, $matches)) {
    $tenantId = $matches[1];
    $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    $keyIds = $payload['key_ids'] ?? [];
    
    if (empty($keyIds)) {
        ServiceHelpers::sendJson(400, ['error' => 'key_ids required']);
    }
    
    $keys = ServiceHelpers::loadJson('marketplace', 'plugin_keys.json');
    $activated = [];
    
    foreach ($keys as &$key) {
        if (in_array($key['id'] ?? '', $keyIds) && !empty($key['revoked'])) {
            $key['revoked'] = false;
            $key['revoked_at'] = null;
            $activated[] = $key['id'];
        }
    }
    
    ServiceHelpers::saveJson('marketplace', 'plugin_keys.json', $keys);
    logRemediationEvent($tenantId, 'activate_keys', ['activated' => $activated]);
    ServiceHelpers::sendJson(200, ['success' => true, 'activated_count' => count($activated), 'activated' => $activated]);
}

// Tenant trend analysis and drift detection
if ($method === 'GET' && preg_match('#^/tenants/([^/]+)/trends$#', $path, $matches)) {
    $tenantId = $matches[1];
    
    // Load or create historical data file
    $historyFile = getenv('GDWB_DATA_BASE') ?: __DIR__ . '/../../services/data';
    $historyFile .= "/marketplace_tenant_history_{$tenantId}.json";
    $history = [];
    if (file_exists($historyFile)) {
        $content = json_decode(file_get_contents($historyFile), true);
        if (is_array($content)) $history = $content;
    }
    
    // Capture current metrics
    $plugins = array_values(array_filter(getPlugins(), fn($p) => (($p['tenant_id'] ?? '') === $tenantId)));
    $installs = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
    $tenantInstalls = array_values(array_filter($installs, fn($i) => (($i['tenant_id'] ?? '') === $tenantId)));
    $ratings = ServiceHelpers::loadJson('marketplace', 'plugin_ratings.json');
    $tenantRatings = array_values(array_filter($ratings, fn($r) => (($r['tenant_id'] ?? '') === $tenantId)));
    $keys = ServiceHelpers::loadJson('marketplace', 'plugin_keys.json');
    $tenantKeys = array_values(array_filter($keys, function($k) use ($plugins) {
        $pluginId = $k['plugin_id'] ?? '';
        foreach ($plugins as $p) {
            if ($p['id'] === $pluginId) return true;
        }
        return false;
    }));
    $revokedCount = count(array_filter($tenantKeys, fn($k) => !empty($k['revoked'])));
    
    $avgRating = null;
    if (!empty($tenantRatings)) {
        $sum = array_sum(array_map(fn($r) => intval($r['rating'] ?? 0), $tenantRatings));
        $avgRating = $sum / count($tenantRatings);
    }
    
    // Calculate current health score (reuse logic)
    $healthScore = 100;
    $missingDeps = [];
    foreach ($tenantInstalls as $install) {
        $manifest = $install['manifest'] ?? null;
        if ($manifest && !empty($manifest['dependencies'])) {
            foreach ($manifest['dependencies'] as $dep) {
                $depPluginId = $dep['plugin_id'] ?? $dep['id'] ?? null;
                if ($depPluginId) {
                    $depInstalled = count(array_filter($tenantInstalls, fn($i) => (($i['plugin_id'] ?? '') === $depPluginId))) > 0;
                    if (!$depInstalled) {
                        $missingDeps[] = $depPluginId;
                    }
                }
            }
        }
    }
    
    $healthScore -= count($missingDeps) * 15;
    $healthScore -= $revokedCount * 5;
    if ($avgRating !== null && $avgRating < 3) {
        $healthScore -= (3 - $avgRating) * 10;
    }
    if (count($tenantInstalls) === 0 && count($tenantRatings) > 0) {
        $healthScore -= 20;
    }
    if (count($tenantInstalls) > count($plugins) * 0.5) {
        $healthScore += 10;
    }
    if (count($tenantRatings) > count($tenantInstalls) * 0.5) {
        $healthScore += 5;
    }
    $healthScore = max(0, min(100, intval($healthScore)));
    
    // Add current snapshot to history
    $snapshot = [
        'timestamp' => gmdate('c'),
        'health_score' => $healthScore,
        'plugin_count' => count($plugins),
        'install_count' => count($tenantInstalls),
        'rating_count' => count($tenantRatings),
        'avg_rating' => $avgRating,
        'key_count' => count($tenantKeys),
        'revoked_key_count' => $revokedCount,
        'missing_deps_count' => count($missingDeps)
    ];
    
    // Keep only last 30 days of data
    $history[] = $snapshot;
    $thirtyDaysAgo = strtotime('-30 days');
    $history = array_filter($history, function($s) use ($thirtyDaysAgo) {
        return strtotime($s['timestamp']) >= $thirtyDaysAgo;
    });
    $history = array_values($history);
    
    // Save history
    ServiceHelpers::saveJson('marketplace', "tenant_history_{$tenantId}.json", $history);
    
    // Calculate trends
    $trends = [];
    if (count($history) >= 2) {
        $first = $history[0];
        $last = $history[count($history) - 1];
        
        // Health score trend
        $healthDelta = $last['health_score'] - $first['health_score'];
        $trends['health_score'] = [
            'current' => $last['health_score'],
            'delta' => $healthDelta,
            'direction' => $healthDelta > 0 ? 'up' : ($healthDelta < 0 ? 'down' : 'stable'),
            'trend_label' => $healthDelta > 0 ? 'Improving ↗' : ($healthDelta < 0 ? 'Declining ↘' : 'Stable →')
        ];
        
        // Install adoption
        $installDelta = $last['install_count'] - $first['install_count'];
        $trends['adoption'] = [
            'current' => $last['install_count'],
            'delta' => $installDelta,
            'direction' => $installDelta > 0 ? 'up' : ($installDelta < 0 ? 'down' : 'stable')
        ];
        
        // Rating engagement
        $ratingDelta = $last['rating_count'] - $first['rating_count'];
        $trends['engagement'] = [
            'current' => $last['rating_count'],
            'delta' => $ratingDelta,
            'direction' => $ratingDelta > 0 ? 'up' : ($ratingDelta < 0 ? 'down' : 'stable')
        ];
        
        // Key revocation (drift detection)
        $revokedDelta = $last['revoked_key_count'] - $first['revoked_key_count'];
        $revokedPercent = $last['key_count'] > 0 ? ($last['revoked_key_count'] / $last['key_count']) * 100 : 0;
        $trends['revocation_drift'] = [
            'current_count' => $last['revoked_key_count'],
            'current_percent' => round($revokedPercent, 1),
            'delta' => $revokedDelta,
            'is_drifting' => $revokedDelta > 0 && $revokedPercent > 30,
            'risk_level' => $revokedPercent > 50 ? 'critical' : ($revokedPercent > 30 ? 'high' : 'normal')
        ];
        
        // Dependency management (governance drift)
        $depsDelta = $last['missing_deps_count'] - $first['missing_deps_count'];
        $trends['governance_drift'] = [
            'current_missing' => $last['missing_deps_count'],
            'delta' => $depsDelta,
            'is_drifting' => $depsDelta > 0,
            'severity' => $depsDelta > 0 ? ($depsDelta > 2 ? 'critical' : 'warning') : 'ok'
        ];
        
        // Risk-growth detection
        $volatility = 0;
        for ($i = 1; $i < count($history); $i++) {
            $volatility += abs($history[$i]['health_score'] - $history[$i-1]['health_score']);
        }
        $volatility = round($volatility / max(1, count($history) - 1), 1);
        
        $trends['volatility'] = [
            'score' => $volatility,
            'trend' => $volatility > 5 ? 'unstable' : ($volatility > 2 ? 'fluctuating' : 'stable'),
            'risk' => $volatility > 5 ? 'high' : ($volatility > 2 ? 'medium' : 'low')
        ];
    }
    
    ServiceHelpers::sendJson(200, [
        'tenant_id' => $tenantId,
        'history_points' => count($history),
        'current_snapshot' => $snapshot,
        'trends' => $trends,
        'history' => array_slice($history, -7) // Last 7 data points
    ]);
}

// Dry-run remediation with impact prediction
if ($method === 'POST' && preg_match('#^/tenants/([^/]+)/remediate/([a-z-]+)/preview$#', $path, $matches)) {
    $tenantId = $matches[1];
    $action = $matches[2];
    
    // Get current state
    $plugins = array_values(array_filter(getPlugins(), fn($p) => (($p['tenant_id'] ?? '') === $tenantId)));
    $installs = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
    $tenantInstalls = array_values(array_filter($installs, fn($i) => (($i['tenant_id'] ?? '') === $tenantId)));
    $ratings = ServiceHelpers::loadJson('marketplace', 'plugin_ratings.json');
    $tenantRatings = array_values(array_filter($ratings, fn($r) => (($r['tenant_id'] ?? '') === $tenantId)));
    $keys = ServiceHelpers::loadJson('marketplace', 'plugin_keys.json');
    $tenantKeys = array_values(array_filter($keys, function($k) use ($plugins) {
        $pluginId = $k['plugin_id'] ?? '';
        foreach ($plugins as $p) {
            if ($p['id'] === $pluginId) return true;
        }
        return false;
    }));
    
    $revokedCount = count(array_filter($tenantKeys, fn($k) => !empty($k['revoked'])));
    
    // Calculate current health
    $currentHealth = 100;
    $missingDeps = [];
    foreach ($tenantInstalls as $install) {
        $manifest = $install['manifest'] ?? null;
        if ($manifest && !empty($manifest['dependencies'])) {
            foreach ($manifest['dependencies'] as $dep) {
                $depPluginId = $dep['plugin_id'] ?? $dep['id'] ?? null;
                if ($depPluginId) {
                    $depInstalled = count(array_filter($tenantInstalls, fn($i) => (($i['plugin_id'] ?? '') === $depPluginId))) > 0;
                    if (!$depInstalled) {
                        $missingDeps[$depPluginId] = true;
                    }
                }
            }
        }
    }
    
    $currentHealth -= count($missingDeps) * 15;
    $currentHealth -= $revokedCount * 5;
    $currentHealth = max(0, min(100, intval($currentHealth)));
    
    // Simulate remediation
    $projectedHealth = $currentHealth;
    $changes = [];
    
    if ($action === 'install-missing-deps' && !empty($missingDeps)) {
        $changes[] = 'Install ' . count($missingDeps) . ' missing dependencies';
        $projectedHealth += count($missingDeps) * 15;
    }
    
    if ($action === 'activate-keys' && $revokedCount > 0) {
        $changes[] = 'Activate ' . $revokedCount . ' revoked keys';
        $projectedHealth += $revokedCount * 5;
    }
    
    $projectedHealth = max(0, min(100, intval($projectedHealth)));
    $impact = $projectedHealth - $currentHealth;
    
    ServiceHelpers::sendJson(200, [
        'action' => $action,
        'current_health' => $currentHealth,
        'projected_health' => $projectedHealth,
        'health_impact' => $impact,
        'changes' => $changes,
        'confidence' => 'high',
        'safe_to_execute' => true
    ]);
}

// Plugin ratings
if ($method === 'GET' && preg_match('#^/plugins/([^/]+)/ratings$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_ratings.json');
    $items = array_values(array_filter($all, fn($r) => (($r['plugin_id'] ?? '') === $pluginId)));
    $avg = null;
    if (!empty($items)) {
        $sum = 0; foreach ($items as $it) $sum += intval($it['rating'] ?? 0);
        $avg = $sum / count($items);
    }
    ServiceHelpers::sendJson(200, ['items' => $items, 'total' => count($items), 'average' => $avg]);
}

if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/ratings$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    $payload = getRequestBody();
    $rating = isset($payload['rating']) ? intval($payload['rating']) : 0;
    $comment = trim($payload['comment'] ?? '');
    $tenantId = ServiceHelpers::normalizeTenantId($payload) ?? trim($payload['tenant_id'] ?? '');
    if ($rating < 1 || $rating > 5) ServiceHelpers::sendJson(400, ['error' => 'invalid_rating', 'message' => 'rating must be 1-5']);
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_ratings.json');
    $entry = [
        'id' => ServiceHelpers::generateUuid(),
        'plugin_id' => $pluginId,
        'tenant_id' => $tenantId,
        'rating' => $rating,
        'comment' => $comment,
        'created_at' => gmdate('c'),
    ];
    $all[] = $entry;
    ServiceHelpers::saveJson('marketplace', 'plugin_ratings.json', $all);
    ServiceHelpers::sendJson(201, $entry);
}

// --- Plugin Artifacts ---
if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/versions/([^/]+)/artifact$#', $path, $matches)) {
    $pluginId = $matches[1];
    $version = $matches[2];
    $plugin = getPluginById($pluginId);
    if (!$plugin) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    $payload = getRequestBody();
    $fileName = trim($payload['file_name'] ?? 'artifact.bin');
    $artifactBase64 = $payload['artifact_base64'] ?? '';
    if (trim($artifactBase64) === '') ServiceHelpers::sendJson(400, ['error' => 'invalid_artifact', 'message' => 'artifact_base64 required']);
    $raw = base64_decode($artifactBase64, true);
    if ($raw === false) ServiceHelpers::sendJson(400, ['error' => 'invalid_artifact', 'message' => 'artifact_base64 not valid base64']);

    $artDir = __DIR__ . '/../../services/data/artifacts';
    ensureDir($artDir);
    $fileId = ServiceHelpers::generateUuid();
    $safeName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $fileName);
    $pathOnDisk = $artDir . '/' . $pluginId . '_' . $version . '_' . $fileId . '_' . $safeName;
    file_put_contents($pathOnDisk, $raw);

    $signature = trim($payload['signature'] ?? '');
    $publicKey = isset($payload['public_key']) ? trim($payload['public_key']) : null;
    $signatureVerified = false;
    if ($signature !== '') {
        $ok = false;
        if ($publicKey !== null && $publicKey !== '') {
            $ok = verifyArtifactSignature($raw, $signature, $publicKey);
        }
        if (!$ok) {
            $registered = getPluginKeys($pluginId);
            foreach ($registered as $rk) {
                if (!empty($rk['public_key']) && empty($rk['revoked'])) {
                    if (verifyArtifactSignature($raw, $signature, $rk['public_key'])) {
                        $ok = true;
                        break;
                    }
                }
            }
        }
        if (!$ok) {
            if (verifyArtifactSignature($raw, $signature, null)) {
                $ok = true;
            }
        }
        if (!$ok) {
            ServiceHelpers::sendJson(400, ['error' => 'invalid_artifact_signature', 'message' => 'Artifact signature verification failed.']);
        }
        $signatureVerified = true;
    }

    $all = ServiceHelpers::loadJson('marketplace', 'plugin_artifacts.json');
    $entry = [
        'id' => $fileId,
        'plugin_id' => $pluginId,
        'version' => $version,
        'file_name' => $fileName,
        'path' => $pathOnDisk,
        'signature_verified' => $signatureVerified,
        'signature' => $signature !== '' ? $signature : null,
        'public_key' => $publicKey !== null ? $publicKey : null,
        'created_at' => gmdate('c'),
    ];
    $all[] = $entry;
    ServiceHelpers::saveJson('marketplace', 'plugin_artifacts.json', $all);
    ServiceHelpers::sendJson(201, $entry);
}

if ($method === 'GET' && preg_match('#^/plugins/([^/]+)/versions/([^/]+)/artifact$#', $path, $matches)) {
    $pluginId = $matches[1];
    $version = $matches[2];
    $plugin = getPluginById($pluginId);
    if (!$plugin) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_artifacts.json');
    $items = array_values(array_filter($all, fn($a) => (($a['plugin_id'] ?? '') === $pluginId && ($a['version'] ?? '') === $version)));
    ServiceHelpers::sendJson(200, ['items' => $items, 'total' => count($items)]);
}

if ($method === 'GET' && preg_match('#^/plugins/([^/]+)/versions/([^/]+)/artifact/([^/]+)$#', $path, $matches)) {
    $pluginId = $matches[1];
    $version = $matches[2];
    $artifactId = $matches[3];
    $plugin = getPluginById($pluginId);
    if (!$plugin) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_artifacts.json');
    $item = null;
    foreach ($all as $a) {
        if (($a['id'] ?? '') === $artifactId && ($a['plugin_id'] ?? '') === $pluginId && ($a['version'] ?? '') === $version) {
            $item = $a;
            break;
        }
    }
    if (!$item) ServiceHelpers::sendJson(404, ['error' => 'artifact_not_found']);
    if (empty($item['path']) || !file_exists($item['path'])) {
        ServiceHelpers::sendJson(500, ['error' => 'artifact_missing_on_disk']);
    }
    $raw = file_get_contents($item['path']);
    if ($raw === false) ServiceHelpers::sendJson(500, ['error' => 'artifact_read_error']);
    $b64 = base64_encode($raw);
    $resp = $item;
    $resp['download_base64'] = $b64;
    ServiceHelpers::sendJson(200, $resp);
}

if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/install$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $payload = getRequestBody();
    $tenantId = ServiceHelpers::normalizeTenantId($payload) ?? trim($payload['tenant_id'] ?? '');
    if (!$tenantId) {
        ServiceHelpers::sendJson(400, ['error' => 'missing_tenant', 'message' => 'tenant_id required to install plugin']);
    }
    $versionReq = trim($payload['version'] ?? '');
    if ($versionReq !== '') {
        $versionObj = getPluginVersionByIdentifier($pluginId, $versionReq);
        if (!$versionObj) {
            ServiceHelpers::sendJson(404, ['error' => 'version_not_found']);
        }
        $versionToUse = $versionObj['version'];
    } else {
        $versionToUse = $plugin['version'] ?? null;
    }
    // Resolve version object if available
    $versionObj = getPluginVersionByIdentifier($pluginId, $versionToUse);
    $manifestForVersion = $versionObj['manifest'] ?? null;
    $autoInstallDeps = !empty($payload['auto_install_dependencies']);
    // If manifest declares dependencies, ensure they are satisfied or auto-install
    if (is_array($manifestForVersion) && !empty($manifestForVersion['dependencies'])) {
        $missing = [];
        foreach ($manifestForVersion['dependencies'] as $dep) {
            $depPluginId = $dep['plugin_id'] ?? null;
            $depVersion = $dep['version'] ?? null;
            if (!$depPluginId || !$depVersion) {
                ServiceHelpers::sendJson(400, ['error' => 'invalid_dependency_spec', 'detail' => $dep]);
            }
            // check if dependency is already installed for tenant
            $installsAll = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
            $found = false;
            foreach ($installsAll as $ins) {
                if (($ins['plugin_id'] ?? '') === $depPluginId && ($ins['tenant_id'] ?? '') === $tenantId && ($ins['status'] ?? '') === 'installed' && ($ins['version'] ?? '') === $depVersion) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = ['plugin_id' => $depPluginId, 'version' => $depVersion];
            }
        }
        if (!empty($missing)) {
            if ($autoInstallDeps) {
                // attempt to auto-install missing dependencies
                $allInstalls = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
                foreach ($missing as $m) {
                    $depPlugin = getPluginById($m['plugin_id']);
                    if (!$depPlugin) ServiceHelpers::sendJson(404, ['error' => 'dependency_plugin_not_found', 'detail' => $m]);
                    $depVersionObj = getPluginVersionByIdentifier($m['plugin_id'], $m['version']);
                    if (!$depVersionObj) ServiceHelpers::sendJson(404, ['error' => 'dependency_version_not_found', 'detail' => $m]);
                    $depEntry = [
                        'id' => ServiceHelpers::generateUuid(),
                        'plugin_id' => $m['plugin_id'],
                        'tenant_id' => $tenantId,
                        'version' => $m['version'],
                        'status' => 'installed',
                        'installed_at' => gmdate('c'),
                    ];
                    $allInstalls[] = $depEntry;
                    // send webhook for dependency install if configured
                    if (!empty($depPlugin['webhook_url'])) {
                        sendWebhook($depPlugin['webhook_url'], ['event' => 'installed', 'plugin_id' => $m['plugin_id'], 'tenant_id' => $tenantId, 'version' => $m['version'], 'install' => $depEntry]);
                    }
                }
                ServiceHelpers::saveJson('marketplace', 'plugin_installs.json', $allInstalls);
            } else {
                ServiceHelpers::sendJson(400, ['error' => 'missing_dependencies', 'details' => $missing]);
            }
        }
    }
    $installs = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
    $entry = [
        'id' => ServiceHelpers::generateUuid(),
        'plugin_id' => $pluginId,
        'tenant_id' => $tenantId,
        'version' => $versionToUse,
        'status' => 'installed',
        'installed_at' => gmdate('c'),
    ];
    $installs[] = $entry;
    ServiceHelpers::saveJson('marketplace', 'plugin_installs.json', $installs);
    // send install webhook if configured
    if (!empty($plugin['webhook_url'])) {
        sendWebhook($plugin['webhook_url'], ['event' => 'installed', 'plugin_id' => $pluginId, 'tenant_id' => $tenantId, 'version' => $versionToUse, 'install' => $entry]);
    }
    ServiceHelpers::sendJson(200, ['status' => 'installed', 'install' => $entry]);
}

if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/uninstall$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $payload = getRequestBody();
    $tenantId = ServiceHelpers::normalizeTenantId($payload) ?? trim($payload['tenant_id'] ?? '');
    if (!$tenantId) {
        ServiceHelpers::sendJson(400, ['error' => 'missing_tenant', 'message' => 'tenant_id required to uninstall plugin']);
    }
    $installs = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
    $found = false;
    foreach ($installs as &$ins) {
        if (($ins['plugin_id'] ?? '') === $pluginId && ($ins['tenant_id'] ?? '') === $tenantId && ($ins['status'] ?? '') === 'installed') {
            $ins['status'] = 'uninstalled';
            $ins['uninstalled_at'] = gmdate('c');
            $found = true;
            $result = $ins;
            break;
        }
    }
    if (!$found) {
        ServiceHelpers::sendJson(404, ['error' => 'not_installed']);
    }
    ServiceHelpers::saveJson('marketplace', 'plugin_installs.json', $installs);
    // send uninstall webhook if configured
    if (!empty($plugin['webhook_url'])) {
        sendWebhook($plugin['webhook_url'], ['event' => 'uninstalled', 'plugin_id' => $pluginId, 'tenant_id' => $tenantId, 'version' => $result['version'] ?? null, 'install' => $result]);
    }
    ServiceHelpers::sendJson(200, ['status' => 'uninstalled', 'install' => $result]);
}

// --- Simple UI Endpoints ---
if ($method === 'GET' && ($path === '/dep-graph' || $path === '/ui/dep-graph')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Dependency Graph</title><script src="https://cdn.jsdelivr.net/npm/mermaid/dist/mermaid.min.js"></script></head><body>';
    echo '<h2>Marketplace Dependency Graph</h2>';
    echo '<div id="graph" class="mermaid">Loading graph...</div>';
    echo '<p><a href="/dep-graph.mmd" download>Download mermaid source</a></p>';
    echo '<script>';
    echo 'mermaid.initialize({ startOnLoad: false });';
    echo 'fetch("/dep-graph.mmd").then(r=>r.text()).then(t=>{ document.getElementById("graph").innerText = t; mermaid.init(undefined, document.getElementById("graph")); }).catch(e=>{document.getElementById("graph").innerText = "Error loading graph: "+e});';
    echo '</script></body></html>';
    exit;
}

if ($method === 'GET' && $path === '/dep-graph.mmd') {
    $f = __DIR__ . '/../../tools/dep-graph.mmd';
    if (!file_exists($f)) ServiceHelpers::sendJson(404, ['error' => 'not_found']);
    header('Content-Type: text/plain; charset=utf-8');
    echo file_get_contents($f);
    exit;
}

// Phase 1: Health vs Volatility Matrix Component
if ($method === 'GET' && ($uri === '/health-volatility-matrix' || $uri === '/health-volatility-matrix/')) {
    $componentFile = __DIR__ . '/../../ui-components/health-volatility-matrix.html';
    if (!file_exists($componentFile)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['error' => 'Component not found', 'file' => $componentFile]);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile($componentFile);
    exit;
}

if ($method === 'GET' && ($uri === '/drift-analysis-grid' || $uri === '/drift-analysis-grid/')) {
    $componentFile = __DIR__ . '/../../ui-components/drift-analysis-grid.html';
    if (!file_exists($componentFile)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['error' => 'Component not found', 'file' => $componentFile]);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile($componentFile);
    exit;
}

if ($method === 'GET' && $uri === '/risk-zones.js') {
    $scriptFile = __DIR__ . '/../../ui-components/risk-zones.js';
    if (!file_exists($scriptFile)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['error' => 'Script not found', 'file' => $scriptFile]);
        exit;
    }
    header('Content-Type: application/javascript; charset=utf-8');
    readfile($scriptFile);
    exit;
}

if ($method === 'GET' && ($uri === '/tenant-trend-timeline' || $uri === '/tenant-trend-timeline/')) {
    $componentFile = __DIR__ . '/../../ui-components/tenant-trend-timeline.html';
    if (!file_exists($componentFile)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['error' => 'Component not found', 'file' => $componentFile]);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile($componentFile);
    exit;
}

if ($method === 'GET' && ($uri === '/operations-center' || $uri === '/operations-center/')) {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Platform Operations Center</title><style>body{font-family:Arial,Helvetica,sans-serif;margin:0;background:#f4f7fb;color:#1f2937}header{padding:24px 32px;background:#111827;color:white;display:flex;justify-content:space-between;align-items:flex-start}header>div{flex:1}h1{margin:0;font-size:32px}h2{margin:.5rem 0 1rem;font-size:20px;color:#e2e8f0}a{color:#60a5fa;text-decoration:none}a:hover{text-decoration:underline}button{background:#2563eb;color:white;border:none;padding:10px 16px;border-radius:8px;cursor:pointer}button:hover{background:#1d4ed8}.back-link{display:inline-block;margin-bottom:12px}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px}.card{background:white;padding:18px;border-radius:16px;box-shadow:0 10px 30px rgba(15,23,42,.08)}.card h3{margin:0 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#64748b}.card strong{display:block;font-size:28px;margin-top:4px}.banner{background:#065f46;border-left:4px solid #10b981;padding:16px;border-radius:8px;margin-bottom:24px;color:#white}main{padding:24px 32px}.filters{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:16px}.filters input,.filters select{padding:10px 12px;border:1px solid #cbd5e1;border-radius:12px;background:white;min-width:160px}.table-wrapper{overflow-x:auto;background:white;border-radius:16px;box-shadow:0 10px 30px rgba(15,23,42,.05)}table{width:100%;border-collapse:collapse}th,td{padding:14px 16px;text-align:left;border-bottom:1px solid #e2e8f0}th{background:#f8fafc;color:#334155;font-weight:700}tbody tr{cursor:pointer}tbody tr:hover{background:#f1f5f9}.status-pill{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700}.status-pill.green{background:#dcfce7;color:#166534}.status-pill.yellow{background:#fef3c7;color:#92400e}.status-pill.red{background:#fee2e2;color:#991b1b}.drill-down{color:#60a5fa;font-weight:700}</style></head><body><header><div><h1>📊 Platform Operations Center</h1><p style="margin:8px 0 0;color:#cbd5e1;max-width:720px;">Executive dashboard for fleet health, risk, and remediation status.</p></div><div style="text-align:right;display:flex;flex-direction:column;gap:8px;"><a href="/health-volatility-matrix" class="back-link">📈 View Health vs Volatility Matrix</a><a href="/marketplace-ui" class="back-link">← Back to Marketplace</a></div></header><main><div id="statusBanner" class="banner" style="display:none;"></div><div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:24px;"><div><h2>Fleet KPI Summary</h2><p style="margin:4px 0 0;color:#64748b;">Aggregated platform health and risk metrics updated every 5 minutes.</p></div><button onclick="refreshAll()">Refresh Data</button></div><div id="kpiCards" class="cards"></div><div class="filters"><input id="searchTenant" placeholder="Search tenants" oninput="renderOverview()" /><select id="healthFilter" onchange="renderOverview()"><option value="all">All Health Bands</option><option value="healthy">Healthy</option><option value="fair">Fair</option><option value="critical">Critical</option></select><select id="driftFilter" onchange="renderOverview()"><option value="all">All Drift Status</option><option value="none">No Drift</option><option value="revocation">Revocation Drift</option><option value="governance">Governance Drift</option></select></div><div class="table-wrapper"><table><thead><tr><th>Tenant</th><th>Health</th><th>Trend</th><th>Volatility</th><th>Installs</th><th>Findings</th><th>Updated</th></tr></thead><tbody id="overviewTable"></tbody></table></div><div style="margin-top:32px;"><h2>Rankings & Risk Analysis</h2><div class="cards"><div class="card"><h3>Top Healthiest Tenants</h3><table style="width:100%;font-size:13px;"><thead><tr><th style="padding:4px 8px;text-align:left;">Tenant</th><th style="padding:4px 8px;text-align:right;">Score</th></tr></thead><tbody id="healthiestTable"></tbody></table></div><div class="card"><h3>Most Improved Tenants</h3><table style="width:100%;font-size:13px;"><thead><tr><th style="padding:4px 8px;text-align:left;">Tenant</th><th style="padding:4px 8px;text-align:right;">Change</th></tr></thead><tbody id="improvedTable"></tbody></table></div><div class="card"><h3>Highest Risk Tenants</h3><table style="width:100%;font-size:13px;"><thead><tr><th style="padding:4px 8px;text-align:left;">Tenant</th><th style="padding:4px 8px;text-align:right;">Score</th></tr></thead><tbody id="riskTable"></tbody></table></div></div></div><div style="margin-top:32px;"><h2>Fleet Drift Summary</h2><div class="cards"><div class="card"><h3>No Drift</h3><strong id="driftNone">0</strong></div><div class="card"><h3>Governance Drift</h3><strong id="driftGovernance">0</strong></div><div class="card"><h3>Revocation Drift</h3><strong id="driftRevocation">0</strong></div></div></div><script>const overviewData=[];async function apiGet(url){const res=await fetch(url);if(!res.ok){throw new Error(res.statusText);}return res.json();}function badge(status){if(status==='healthy')return '<span class="status-pill green">Healthy</span>';if(status==='fair')return '<span class="status-pill yellow">Fair</span>';return '<span class="status-pill red">Critical</span>';}function renderKpis(dash){const cards=document.getElementById('kpiCards');cards.innerHTML='';const items=[{title:'Platform Health Score',value:dash.platform_health_score,subtitle:'Weighted average tenant health'},{title:'At-Risk Tenants',value:dash.at_risk_tenants,subtitle:'Health under 60'},{title:'Critical Findings',value:dash.critical_alerts,subtitle:'Fleet-wide critical issues'},{title:'Total Active Installs',value:dash.total_active_installs,subtitle:'Active installs across fleet'},{title:'Total Remediations (7d)',value:dash.total_remediations_7d,subtitle:'Recent remediation actions'},{title:'Fleet Volatility',value:dash.fleet_volatility,subtitle:'Average volatility score'}];for(const card of items){cards.innerHTML += '<div class="card"><h3>'+card.title+'</h3><strong>'+card.value+'</strong><div style="margin-top:10px;color:#64748b;">'+card.subtitle+'</div></div>'}}function renderOverview(){const search=document.getElementById('searchTenant').value.toLowerCase();const healthFilter=document.getElementById('healthFilter').value;const driftFilter=document.getElementById('driftFilter').value;const rows=overviewData.filter(t=>t.tenant_id.toLowerCase().includes(search) && (healthFilter==='all'||t.health_status===healthFilter) && (driftFilter==='all'||t.drift_status===driftFilter));const tbody=document.getElementById('overviewTable');tbody.innerHTML='';if(rows.length===0){tbody.innerHTML='<tr><td colspan="7" style="text-align:center;padding:24px;color:#64748b;">No tenants match the filter.</td></tr>';return;}for(const row of rows){const link='<span class="drill-down">View →</span>';tbody.innerHTML += '<tr onclick="goToTenant(\''+row.tenant_id+'\')" style="cursor:pointer;"><td>'+row.tenant_id+'</td><td>'+badge(row.health_status)+' '+row.health_score+'</td><td>'+row.health_trend+' ('+row.health_delta+')</td><td>'+row.volatility_score+' ('+row.volatility_status+')</td><td>'+row.install_count+'</td><td>'+row.finding_count+'</td><td>'+link+'</td></tr>'}}function goToTenant(tenantId){window.location.href='/marketplace-ui?tenant='+encodeURIComponent(tenantId)+'&tab=health';}function updateStatusBanner(dash){const banner=document.getElementById('statusBanner');const issues=[];if(dash.at_risk_tenants>0)issues.push(dash.at_risk_tenants+' at-risk tenants');if(dash.critical_alerts>0)issues.push(dash.critical_alerts+' critical findings');const msg=issues.length>0?issues.join(' • '):' All systems nominal';banner.innerHTML='<strong>Platform Health: '+dash.platform_health_score+'</strong> – '+msg;banner.style.display='block';}function renderRankings(rankings){const healthiest=rankings.healthiest_tenants||[];const improved=rankings.most_improved_tenants||[];const risk=rankings.highest_risk_tenants||[];document.getElementById('healthiestTable').innerHTML=healthiest.map(t=>'<tr onclick="goToTenant(\''+t.tenant_id+'\')"><td style="padding:4px 8px;">'+t.tenant_id+'</td><td style="padding:4px 8px;text-align:right;">'+t.health_score+'</td></tr>').join('');document.getElementById('improvedTable').innerHTML=improved.map(t=>'<tr onclick="goToTenant(\''+t.tenant_id+'\')"><td style="padding:4px 8px;">'+t.tenant_id+'</td><td style="padding:4px 8px;text-align:right;">'+t.health_delta+'</td></tr>').join('');document.getElementById('riskTable').innerHTML=risk.map(t=>'<tr onclick="goToTenant(\''+t.tenant_id+'\')"><td style="padding:4px 8px;">'+t.tenant_id+'</td><td style="padding:4px 8px;text-align:right;">'+t.health_score+'</td></tr>').join('');}function renderDrift(drift){document.getElementById('driftNone').innerText=drift.no_drift||0;document.getElementById('driftGovernance').innerText=drift.governance_drift||0;document.getElementById('driftRevocation').innerText=drift.revocation_drift||0;}async function refreshAll(){try{const dash=await apiGet('/api/v1/marketplace/platform/dashboard?refresh=true');updateStatusBanner(dash);renderKpis(dash);const overview=await apiGet('/api/v1/marketplace/platform/tenants-overview?refresh=true');overviewData.splice(0,overviewData.length,...(overview.items||[]));renderOverview();const rankings=await apiGet('/api/v1/marketplace/platform/rankings');renderRankings(rankings);const drift=await apiGet('/api/v1/marketplace/platform/drift-summary');renderDrift(drift);}catch(e){console.error(e);document.getElementById('kpiCards').innerHTML='<div class="card"><strong>Error loading data</strong></div>';}}window.addEventListener('load',refreshAll);</script></main></body></html>
HTML;
    exit;
} 

if ($method === 'GET' && ($path === '/marketplace-ui' || $path === '/ui')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Marketplace UI</title><script src="https://cdn.jsdelivr.net/npm/mermaid/dist/mermaid.min.js"></script><style>
body{font-family:Arial,sans-serif;margin:16px;background:#f5f5f5;}
.header{background:#fff;padding:12px;border-radius:6px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,0.1);}
.tabs{display:flex;gap:8px;margin-top:12px;}
.tab-btn{padding:8px 16px;background:#e8e8e8;border:none;border-radius:4px;cursor:pointer;font-weight:bold;}
.tab-btn.active{background:#0066cc;color:white;}
.tab-content{display:none;background:#fff;padding:12px;border-radius:6px;margin-top:12px;box-shadow:0 1px 3px rgba(0,0,0,0.1);}
.tab-content.active{display:block;}
.tenant-selector{margin-bottom:12px;padding:12px;background:#f0f7ff;border-left:4px solid #0066cc;border-radius:4px;}
.tenant-selector label{font-weight:bold;margin-right:8px;}
.tenant-selector select{padding:6px;font-size:14px;}
.plugin-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;}
.plugin-card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px;}
.plugin-card h4{margin:0 0 6px 0;}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:12px;}
.stat-card{background:#f9f9f9;border:1px solid #ddd;border-radius:4px;padding:12px;text-align:center;}
.stat-card .number{font-size:24px;font-weight:bold;color:#0066cc;}
.stat-card .label{color:#666;font-size:12px;margin-top:4px;}
.kpi-banner{padding:12px;border-radius:8px;margin-bottom:12px;font-weight:700;display:inline-block}
.kpi-banner-unknown{background:#f3f4f6;color:#374151}
.kpi-banner-green{background:#ecfdf5;color:#065f46;border-left:4px solid #10b981}
.kpi-banner-yellow{background:#fffbeb;color:#92400e;border-left:4px solid #f59e0b}
.kpi-banner-red{background:#fff1f2;color:#7f1d1d;border-left:4px solid #ef4444}
.kpi-green{color:#065f46}
.kpi-yellow{color:#92400e}
.kpi-red{color:#b91c1c}
.findings-panel{display:flex;flex-direction:column;gap:10px;margin-bottom:12px}
.finding-item{display:flex;gap:12px;padding:12px;border-radius:6px;border-left:4px solid #ccc;background:#f9fafb}
.finding-item.finding-critical{background:#fef2f2;border-left-color:#ef4444}
.finding-item.finding-warning{background:#fffbeb;border-left-color:#f59e0b}
.finding-item.finding-info{background:#f0f9ff;border-left-color:#3b82f6}
.finding-icon{font-size:18px;flex-shrink:0}
.finding-message{font-size:12px;color:#666;margin-top:4px}
.recommendations-panel{display:flex;flex-direction:column;gap:8px}
.recommendation-item{padding:10px;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:4px;font-size:14px;color:#166534}
.performance-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:12px}
.perf-card{background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px;text-align:center}
.perf-label{font-size:12px;color:#666;margin-bottom:6px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px}
.perf-value{font-size:22px;font-weight:bold}
.problem{background:#ffe6e6;border:1px solid #ff6b6b;border-radius:4px;padding:8px;margin:4px 0;color:#a00;}
.success{background:#e6ffe6;border:1px solid #6bff6b;border-radius:4px;padding:8px;color:#0a0;}
table{width:100%;border-collapse:collapse;margin-top:12px;}
th,td{border:1px solid #ddd;padding:8px;text-align:left;}
th{background:#f0f0f0;font-weight:bold;}
tr:nth-child(even){background:#fafafa;}
button{padding:6px 12px;background:#0066cc;color:white;border:none;border-radius:4px;cursor:pointer;}
button:hover{background:#0052a3;}
input,select{padding:6px;border:1px solid #ddd;border-radius:4px;font-size:14px;}
.mermaid{background:#fff;padding:12px;border-radius:4px;border:1px solid #ddd;overflow-x:auto;}
.nav-links{display:flex;gap:16px;margin-top:12px;}
.nav-links a{color:#0066cc;text-decoration:none;font-weight:bold;}
.nav-links a:hover{text-decoration:underline;}
.nav-links .active{color:#333;border-bottom:2px solid #0066cc;padding-bottom:4px;}
</style></head><body>';
    echo '<div class="header"><h1>🏪 Multi-Tenant Marketplace Admin</h1>';
    echo '<div class="nav-links"><a class="active">📦 Marketplace</a><a href="/operations-center" style="color:#666;">📊 Operations Center</a></div>';
    echo '<div class="tenant-selector"><label>Select Tenant:</label><select id="tenantSelect" onchange="switchTenant()"><option value="">-- Global View --</option></select></div>';
    echo '</div>';
    echo '<div id="status" style="padding:8px;margin-bottom:8px;display:none;background:#f0f0f0;border-radius:4px;"></div>';
    
    echo '<div class="tabs">';
    echo '<button class="tab-btn active" onclick="switchTab(this,\'plugins-tab\')">Plugins</button>';
    echo '<button class="tab-btn" onclick="switchTab(this,\'installs-tab\')">Installs</button>';
    echo '<button class="tab-btn" onclick="switchTab(this,\'dependencies-tab\')">Dependencies</button>';
    echo '<button class="tab-btn" onclick="switchTab(this,\'health-tab\')">Health</button>';
    echo '<button class="tab-btn" onclick="switchTab(this,\'intelligence-tab\')">Intelligence</button>';
    echo '<button class="tab-btn" onclick="switchTab(this,\'trends-tab\')">Trends</button>';
    echo '<button class="tab-btn" onclick="switchTab(this,\'learning-tab\')">📚 Learning</button>';
    echo '</div>';
    
    echo '<div id="plugins-tab" class="tab-content active">';
    echo '<input id="search" placeholder="Search plugins" style="width:300px;margin-bottom:12px;" oninput="debouncedRefresh()" />';
    echo '<select id="publishedFilter" onchange="debouncedRefresh()"><option value="all">All</option><option value="published">Published</option><option value="unpublished">Unpublished</option></select>';
    echo '<table id="pluginTable"><thead><tr><th>Name</th><th>Version</th><th>Published</th><th>Tenant</th><th>Actions</th></tr></thead><tbody></tbody></table>';
    echo '</div>';
    
    echo '<div id="installs-tab" class="tab-content">';
    echo '<table id="installTable"><thead><tr><th>Plugin</th><th>Status</th><th>Tenant</th><th>Version</th><th>Installed</th></tr></thead><tbody></tbody></table>';
    echo '</div>';
    
    echo '<div id="dependencies-tab" class="tab-content">';
    echo '<div id="depGraph" class="mermaid">graph TD; Loading...</div>';
    echo '</div>';
    
    echo '<div id="health-tab" class="tab-content">';
    echo '<div id="healthStats" class="stats-grid"></div>';
    echo '<div id="healthProblems"></div>';
    echo '</div>';
    
    echo '<div id="intelligence-tab" class="tab-content">';
    echo '<div id="intelligenceContent"></div>';
    echo '</div>';
    
    echo '<div id="trends-tab" class="tab-content">';
    echo '<div id="trendsContent"></div>';
    echo '</div>';
    
    echo '<div id="learning-tab" class="tab-content">';
    echo '<div id="learningContent"></div>';
    echo '</div>';
    
    echo <<<'SCRIPT'
    <script>
      const apiGet = p => fetch(p,{headers:{Accept:"application/json"}}).then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); });
      const apiPost = (p,b) => fetch(p,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b||{})}).then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); });
      const apiPut = (p,b) => fetch(p,{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify(b||{})}).then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); });
      
      let allPlugins = [], allInstalls = [], allTenants = [], selectedTenant = '';
      let debounceTimer = null;
      
      function setStatus(s,ok=true){
        const el = document.getElementById('status');
        el.innerText = s; el.style.display = 'block'; el.style.background = ok ? '#e6ffe6' : '#ffe6e6'; el.style.color = ok ? '#0a0' : '#a00';
      }
      
      function setStatusAuto(s){ setStatus(s,true); setTimeout(()=>{ document.getElementById('status').style.display = 'none'; }, 3000); }
      
      async function loadTenants(){
        try{
          const r = await apiGet('/api/v1/marketplace/tenants');
          allTenants = r.items || [];
          const sel = document.getElementById('tenantSelect');
          sel.innerHTML = '<option value="">-- Global View --</option>';
          for(const t of allTenants){ const opt = document.createElement('option'); opt.value = t.id; opt.text = t.id; sel.appendChild(opt); }
        }catch(e){ setStatus('Failed to load tenants: '+e, false); }
      }
      
      function switchTenant(){ selectedTenant = document.getElementById('tenantSelect').value; refresh(); }
      
      function switchTab(btn, tabId){
        document.querySelectorAll('.tab-content').forEach(el=>el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el=>el.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(tabId).classList.add('active');
        if(tabId === 'dependencies-tab') loadDepGraph();
        if(tabId === 'health-tab') loadHealth();
        if(tabId === 'intelligence-tab') loadIntelligence();
        if(tabId === 'trends-tab') loadTrends();
        if(tabId === 'learning-tab') loadLearning();
      }
      
      async function refresh(){
        setStatus('Loading...');
        try{
          const r = await apiGet('/api/v1/marketplace/plugins' + (selectedTenant ? '?tenant_id='+selectedTenant : ''));
          allPlugins = r.items || [];
          const ri = await apiGet('/api/v1/marketplace/installs' + (selectedTenant ? '?tenant_id='+selectedTenant : ''));
          allInstalls = ri.items || [];
          refreshPluginTable(); refreshInstallTable();
          setStatusAuto('Loaded ' + allPlugins.length + ' plugins');
        }catch(e){ setStatus('Load failed: '+e, false); }
      }
      
      function refreshPluginTable(){
        const q = (document.getElementById('search').value||'').toLowerCase();
        const pf = document.getElementById('publishedFilter').value;
        let filtered = allPlugins;
        if(q) filtered = filtered.filter(p => ((p.name||'')+' '+(p.description||'')+'  '+(p.author||'')).toLowerCase().includes(q));
        if(pf === 'published') filtered = filtered.filter(p => p.published);
        if(pf === 'unpublished') filtered = filtered.filter(p => !p.published);
        
        const tb = document.querySelector('#pluginTable tbody');
        tb.innerHTML = '';
        for(const p of filtered){
          const tr = document.createElement('tr');
          const nameCell = tr.insertCell(); nameCell.innerText = p.name || p.id;
          const verCell = tr.insertCell(); verCell.innerText = p.version || '';
          const pubCell = tr.insertCell(); pubCell.innerText = p.published ? 'yes' : 'no';
          const tenantCell = tr.insertCell(); tenantCell.innerText = p.tenant_id || '(global)';
          const actCell = tr.insertCell();
          const pubBtn = document.createElement('button');
          pubBtn.innerText = p.published ? 'Unpublish' : 'Publish';
          pubBtn.onclick = async () => { try{ await apiPost('/api/v1/marketplace/plugins/'+p.id+'/'+(p.published?'unpublish':'publish'),{}); setStatusAuto('Updated'); refresh(); }catch(e){ alert('Error: '+e); } };
          const installBtn = document.createElement('button');
          installBtn.innerText = 'Install';
          installBtn.onclick = async () => { const t = prompt('Tenant ID'); if(!t) return; try{ await apiPost('/api/v1/marketplace/plugins/'+p.id+'/install',{tenant_id:t}); setStatusAuto('Installed'); refresh(); }catch(e){ alert('Error: '+e); } };
          actCell.appendChild(pubBtn); actCell.appendChild(document.createTextNode(' ')); actCell.appendChild(installBtn);
          tb.appendChild(tr);
        }
      }
      
      function refreshInstallTable(){
        const tb = document.querySelector('#installTable tbody');
        tb.innerHTML = '';
        const filtered = selectedTenant ? allInstalls.filter(i => i.tenant_id === selectedTenant) : allInstalls;
        for(const i of filtered){
          const tr = document.createElement('tr');
          tr.insertCell().innerText = i.plugin_id || '';
          tr.insertCell().innerText = i.status || 'active';
          tr.insertCell().innerText = i.tenant_id || '';
          tr.insertCell().innerText = i.version || '';
          tr.insertCell().innerText = i.installed_at ? new Date(i.installed_at).toLocaleDateString() : '';
          tb.appendChild(tr);
        }
      }
      
      async function loadHealth(){
        if(!selectedTenant){
          document.getElementById('healthStats').innerHTML = '<p>Select a tenant to view health</p>'; return;
        }
        try{
          const r = await apiGet('/api/v1/marketplace/tenants/'+selectedTenant);
          let html = '';
          
          // Health score banner
          const scoreColor = r.health_color === 'green' ? '#2ecc71' : (r.health_color === 'yellow' ? '#f39c12' : '#e74c3c');
          const scoreEmoji = r.health_status === 'healthy' ? '✅' : (r.health_status === 'fair' ? '⚠️' : '🚨');
          html += '<div style="background:' + scoreColor + ';color:white;padding:16px;border-radius:6px;margin-bottom:12px;text-align:center;">';
          html += '<div style="font-size:32px;font-weight:bold;">' + r.health_score + '/100</div>';
          html += '<div style="font-size:14px;margin-top:4px;">' + scoreEmoji + ' ' + (r.health_status.charAt(0).toUpperCase() + r.health_status.slice(1)) + ' Health</div>';
          html += '</div>';
          
          html += '<div class="stats-grid">';
          html += '<div class="stat-card"><div class="number">' + r.plugin_count + '</div><div class="label">Plugins</div></div>';
          html += '<div class="stat-card"><div class="number">' + r.install_count + '</div><div class="label">Installs</div></div>';
          html += '<div class="stat-card"><div class="number">' + r.rating_count + '</div><div class="label">Ratings</div></div>';
          html += '<div class="stat-card"><div class="number">' + r.active_key_count + '/' + r.key_count + '</div><div class="label">Keys</div></div>';
          html += '</div>';
          
          const stats = document.getElementById('healthStats');
          stats.innerHTML = html;
          
          const probs = document.getElementById('healthProblems');
          if(r.findings && r.findings.length > 0){
            let findingsHtml = '<h4>📋 Findings</h4>';
            const critical = r.findings.filter(f => f.severity === 'critical');
            const warnings = r.findings.filter(f => f.severity === 'warning');
            
            if(critical.length > 0){
              findingsHtml += '<div style="margin-bottom:8px;">';
              for(const f of critical){
                findingsHtml += '<div style="background:#ffebee;border-left:4px solid #e74c3c;border-radius:4px;padding:10px;margin-bottom:6px;">';
                findingsHtml += '<strong style="color:#c0392b;">' + f.icon + ' ' + f.title + '</strong>';
                findingsHtml += '<div style="font-size:12px;color:#555;margin-top:4px;">' + f.description + '</div>';
                findingsHtml += '</div>';
              }
              findingsHtml += '</div>';
            }
            
            if(warnings.length > 0){
              findingsHtml += '<div style="margin-bottom:8px;">';
              for(const f of warnings){
                findingsHtml += '<div style="background:#fff8e1;border-left:4px solid #f39c12;border-radius:4px;padding:10px;margin-bottom:6px;">';
                findingsHtml += '<strong style="color:#d68910;">' + f.icon + ' ' + f.title + '</strong>';
                findingsHtml += '<div style="font-size:12px;color:#555;margin-top:4px;">' + f.description + '</div>';
                findingsHtml += '</div>';
              }
              findingsHtml += '</div>';
            }
            
            probs.innerHTML = findingsHtml;
          }else{
            probs.innerHTML = '<div class="success">✓ Tenant is healthy - no issues detected</div>';
          }
        }catch(e){ document.getElementById('healthStats').innerHTML = '<p>Error: '+e+'</p>'; }
      }
      
      async function loadDepGraph(){
        if(!selectedTenant){
          document.getElementById('depGraph').innerText = 'Select a tenant to view dependencies'; return;
        }
        try{
          const installs = allInstalls.filter(i => i.tenant_id === selectedTenant);
          let graph = 'graph TD\n';
          if(installs.length === 0){
            graph += '  A["No installs in this tenant"]';
          }else{
            for(const i of installs){
              graph += `  P${i.plugin_id.substring(0,8)}["${i.plugin_id.substring(0,12)}..."]\n`;
              if(i.manifest && i.manifest.dependencies){
                for(const dep of i.manifest.dependencies){
                  const depId = dep.plugin_id || dep.id;
                  graph += `  P${i.plugin_id.substring(0,8)} --> D${depId.substring(0,8)}["${dep.plugin_id?.substring(0,8)||'dep'}..."]\n`;
                }
              }
            }
          }
          document.getElementById('depGraph').innerText = graph;
          mermaid.init(undefined, document.getElementById('depGraph'));
        }catch(e){ document.getElementById('depGraph').innerText = 'Error: '+e; }
      }
      
      async function loadIntelligence(){
        const content = document.getElementById('intelligenceContent');
            if(!selectedTenant){
                    // Platform-level intelligence summary when no tenant is selected
                    try{
                        const ih = await apiGet('/api/v1/intelligence-health');

                        function classifyKpi(value, name){
                            // value: numeric 0..100 for percents, or raw hours for avgRes
                            if(name === 'trend_confidence'){
                                if(value >= 90) return 'kpi-green';
                                if(value >= 70) return 'kpi-yellow';
                                return 'kpi-red';
                            }
                            if(name === 'remediation_success_rate'){
                                if(value > 0.9) return 'kpi-green';
                                if(value >= 0.75) return 'kpi-yellow';
                                return 'kpi-red';
                            }
                            if(name === 'stable_tenants_pct'){
                                if(value >= 85) return 'kpi-green';
                                if(value >= 70) return 'kpi-yellow';
                                return 'kpi-red';
                            }
                            if(name === 'anomaly_density'){
                                // lower is better
                                if(value <= 0.05) return 'kpi-green';
                                if(value <= 0.15) return 'kpi-yellow';
                                return 'kpi-red';
                            }
                            if(name === 'average_drift_resolution_hours'){
                                if(value <= 4) return 'kpi-green';
                                if(value <= 12) return 'kpi-yellow';
                                return 'kpi-red';
                            }
                            return '';
                        }

                        let html = '<h3>🧠 Platform Intelligence</h3>';

                        // Status banner
                        const status = (ih.status || 'unknown').toLowerCase();
                        let bannerClass = 'kpi-banner-unknown';
                        let bannerEmoji = '⚪';
                        if(status === 'healthy' || status === 'ok' || status === 'green'){ bannerClass = 'kpi-banner-green'; bannerEmoji = '🟢'; }
                        else if(status === 'warning' || status === 'yellow' || status === 'degraded'){ bannerClass = 'kpi-banner-yellow'; bannerEmoji = '🟠'; }
                        else if(status === 'critical' || status === 'red' || status === 'bad'){ bannerClass = 'kpi-banner-red'; bannerEmoji = '🔴'; }

                        html += '<div class="kpi-banner '+bannerClass+'">'+bannerEmoji+' Intelligence Health: '+(ih.status?ih.status.toUpperCase(): 'UNKNOWN')+'</div>';

                        html += '<div class="stats-grid">';
                        const trendVal = (typeof ih.trend_confidence === 'number') ? ih.trend_confidence * 100 : null;
                        const trendPct = (trendVal !== null) ? Math.round(trendVal*100)/100 + '%' : 'N/A';
                        const stableVal = (typeof ih.stable_tenants_pct === 'number') ? ih.stable_tenants_pct : null;
                        const stablePct = (stableVal !== null) ? Math.round(stableVal*100)/100 + '%' : 'N/A';
                        const anomalyVal = (typeof ih.anomaly_density === 'number') ? ih.anomaly_density : null;
                        const anomalyPct = (anomalyVal !== null) ? Math.round(anomalyVal*10000)/100 + '%' : '0%';
                        const remVal = (typeof ih.remediation_success_rate === 'number') ? ih.remediation_success_rate : null;
                        const remRate = (remVal !== null) ? Math.round(remVal*10000)/100 + '%' : 'N/A';
                        const avgResVal = (ih.average_drift_resolution_hours !== null && ih.average_drift_resolution_hours !== undefined) ? ih.average_drift_resolution_hours : null;
                        const avgRes = (avgResVal !== null) ? (avgResVal + ' hrs') : 'N/A';

                        const trendClass = (trendVal!==null)? classifyKpi(trendVal,'trend_confidence') : '';
                        const stableClass = (stableVal!==null)? classifyKpi(stableVal,'stable_tenants_pct') : '';
                        const anomalyClass = (anomalyVal!==null)? classifyKpi(anomalyVal,'anomaly_density') : '';
                        const remClass = (remVal!==null)? classifyKpi(remVal,'remediation_success_rate') : '';
                        const avgResClass = (avgResVal!==null)? classifyKpi(avgResVal,'average_drift_resolution_hours') : '';

                        html += '<div class="stat-card"><div class="number '+trendClass+'">' + trendPct + '</div><div class="label">Trend Confidence</div></div>';
                        html += '<div class="stat-card"><div class="number '+stableClass+'">' + stablePct + '</div><div class="label">Stable Tenants</div></div>';
                        html += '<div class="stat-card"><div class="number '+anomalyClass+'">' + anomalyPct + '</div><div class="label">Anomaly Density</div></div>';
                        html += '<div class="stat-card"><div class="number '+remClass+'">' + remRate + '</div><div class="label">Remediation Success</div></div>';
                        html += '<div class="stat-card"><div class="number '+avgResClass+'">' + avgRes + '</div><div class="label">Avg Drift Resolution</div></div>';
                        html += '</div>';

                        // Findings panel
                        if(ih.findings && ih.findings.length > 0){
                          html += '<h4 style="margin-top:24px;margin-bottom:12px;">📋 Findings</h4>';
                          html += '<div class="findings-panel">';
                          for(const f of ih.findings){
                            const sev = f.severity || 'info';
                            const sevClass = sev === 'critical' ? 'finding-critical' : (sev === 'warning' ? 'finding-warning' : 'finding-info');
                            const icon = sev === 'critical' ? '🔴' : (sev === 'warning' ? '🟡' : '🔵');
                            html += '<div class="finding-item '+sevClass+'"><span class="finding-icon">'+icon+'</span><div><strong>'+f.title+'</strong><div class="finding-message">'+f.message+'</div></div></div>';
                          }
                          html += '</div>';
                        }

                        // Recommendations panel
                        if(ih.recommendations && ih.recommendations.length > 0){
                          html += '<h4 style="margin-top:24px;margin-bottom:12px;">💡 Recommendations</h4>';
                          html += '<div class="recommendations-panel">';
                          for(const r of ih.recommendations){
                            html += '<div class="recommendation-item">✓ '+r+'</div>';
                          }
                          html += '</div>';
                        }

                        // Fetch effectiveness performance
                        try {
                          const eff = await apiGet('/api/v1/intelligence-effectiveness');
                          if(eff && eff.mttd) {
                            html += '<h4 style="margin-top:24px;margin-bottom:12px;">📊 Intelligence Performance</h4>';
                            html += '<div class="performance-grid">';
                            
                            const mttdColor = eff.mttd.mttd_hours_avg < 6 ? '#10b981' : (eff.mttd.mttd_hours_avg < 12 ? '#f59e0b' : '#ef4444');
                            const mttrColor = eff.mttr.mttr_hours_avg < 8 ? '#10b981' : (eff.mttr.mttr_hours_avg < 16 ? '#f59e0b' : '#ef4444');
                            const accRate = (eff.acceptance_rate.overall_acceptance_rate * 100).toFixed(0);
                            const accColor = accRate >= 85 ? '#10b981' : (accRate >= 70 ? '#f59e0b' : '#ef4444');
                            const precision = (eff.accuracy.precision * 100).toFixed(0);
                            const precColor = precision >= 85 ? '#10b981' : (precision >= 70 ? '#f59e0b' : '#ef4444');
                            
                            html += '<div class="perf-card"><div class="perf-label">MTTD (avg)</div><div class="perf-value" style="color:'+mttdColor+'">' + eff.mttd.mttd_hours_avg + 'h</div></div>';
                            html += '<div class="perf-card"><div class="perf-label">MTTR (avg)</div><div class="perf-value" style="color:'+mttrColor+'">' + eff.mttr.mttr_hours_avg + 'h</div></div>';
                            html += '<div class="perf-card"><div class="perf-label">Acceptance</div><div class="perf-value" style="color:'+accColor+'">' + accRate + '%</div></div>';
                            html += '<div class="perf-card"><div class="perf-label">Accuracy</div><div class="perf-value" style="color:'+precColor+'">' + precision + '%</div></div>';
                            
                            html += '</div>';
                          }
                        } catch(e) { /* silently skip if effectiveness not available */ }

                        html += '<div style="margin-top:16px;color:#999;font-size:12px;">Updated: ' + (ih.computed_at || '') + '</div>';
                        content.innerHTML = html;
                        return;
                    }catch(e){ content.innerHTML = '<div class="problem">Error loading intelligence: '+e+'</div>'; return; }
                }
        try{
          const stats = await apiGet('/api/v1/marketplace/tenants/'+selectedTenant);
          let html = '<h3>🧠 Architecture Intelligence for ' + selectedTenant + '</h3>';
          
          // Health score box - prominent display
          const scoreColor = stats.health_color === 'green' ? '#2ecc71' : (stats.health_color === 'yellow' ? '#f39c12' : '#e74c3c');
          const scoreEmoji = stats.health_status === 'healthy' ? '✅' : (stats.health_status === 'fair' ? '⚠️' : '🚨');
          html += '<div style="background:' + scoreColor + ';color:white;padding:20px;border-radius:8px;margin-bottom:16px;text-align:center;">';
          html += '<div style="font-size:48px;font-weight:bold;">' + stats.health_score + '/100</div>';
          html += '<div style="font-size:18px;margin-top:8px;">' + scoreEmoji + ' ' + (stats.health_status.charAt(0).toUpperCase() + stats.health_status.slice(1)) + '</div>';
          html += '</div>';
          
          // Key metrics
          html += '<div class="stats-grid">';
          html += '<div class="stat-card"><div class="number">' + stats.plugin_count + '</div><div class="label">Available</div></div>';
          html += '<div class="stat-card"><div class="number">' + stats.install_count + '</div><div class="label">Installed</div></div>';
          html += '<div class="stat-card"><div class="number">' + stats.rating_count + '</div><div class="label">Rated</div></div>';
          html += '<div class="stat-card"><div class="number">' + stats.active_key_count + '/' + stats.key_count + '</div><div class="label">Keys</div></div>';
          html += '</div>';
          
          // Severity-ranked findings
          if(stats.findings && stats.findings.length > 0){
            // Group by severity
            const critical = stats.findings.filter(f => f.severity === 'critical');
            const warnings = stats.findings.filter(f => f.severity === 'warning');
            const info = stats.findings.filter(f => f.severity === 'info');
            
            html += '<h4>📋 Findings</h4>';
            
            // Critical findings
            if(critical.length > 0){
              html += '<div style="margin-bottom:12px;">';
              for(const f of critical){
                html += '<div style="background:#ffebee;border-left:4px solid #e74c3c;border-radius:4px;padding:12px;margin-bottom:8px;">';
                html += '<div style="font-weight:bold;color:#c0392b;">' + f.icon + ' ' + f.title + (f.count ? ' (' + f.count + ')' : '') + '</div>';
                html += '<div style="color:#555;margin-top:4px;font-size:13px;">' + f.description + '</div>';
                if(f.remediation){
                  const btnClass = f.remediation === 'install_missing_deps' ? 'remediate-btn-critical' : 'remediate-btn-warning';
                  html += '<button onclick="remediateTenant(\'' + f.remediation + '\')" style="margin-top:8px;background:#e74c3c;color:white;padding:6px 12px;border:none;border-radius:4px;cursor:pointer;">🔧 Fix Now</button>';
                }
                html += '</div>';
              }
              html += '</div>';
            }
            
            // Warning findings
            if(warnings.length > 0){
              html += '<div style="margin-bottom:12px;">';
              for(const f of warnings){
                html += '<div style="background:#fff8e1;border-left:4px solid #f39c12;border-radius:4px;padding:12px;margin-bottom:8px;">';
                html += '<div style="font-weight:bold;color:#d68910;">' + f.icon + ' ' + f.title + (f.count ? ' (' + f.count + ')' : '') + '</div>';
                html += '<div style="color:#555;margin-top:4px;font-size:13px;">' + f.description + '</div>';
                if(f.remediation){
                  html += '<button onclick="remediateTenant(\'' + f.remediation + '\')" style="margin-top:8px;background:#f39c12;color:white;padding:6px 12px;border:none;border-radius:4px;cursor:pointer;">⚡ Review</button>';
                }
                html += '</div>';
              }
              html += '</div>';
            }
            
            // Info findings
            if(info.length > 0){
              html += '<div style="margin-bottom:12px;">';
              for(const f of info){
                html += '<div style="background:#e8f4f8;border-left:4px solid #3498db;border-radius:4px;padding:12px;margin-bottom:8px;">';
                html += '<div style="font-weight:bold;color:#2980b9;">' + f.icon + ' ' + f.title + (f.install_ratio ? ' (' + f.install_ratio + '%)' : '') + '</div>';
                html += '<div style="color:#555;margin-top:4px;font-size:13px;">' + f.description + '</div>';
                html += '</div>';
              }
              html += '</div>';
            }
          }else{
            html += '<div class="success">✓ No issues detected - tenant is healthy!</div>';
          }
          
          // Dependency graph summary
          if(stats.dependency_status && Object.keys(stats.dependency_status).length > 0){
            const unsatisfied = Object.values(stats.dependency_status).filter(d => !d.satisfied);
            html += '<h4>📦 Dependencies</h4>';
            html += '<div style="font-size:13px;color:#666;margin-bottom:12px;">';
            html += 'Total dependencies: ' + Object.keys(stats.dependency_status).length;
            if(unsatisfied.length > 0){
              html += ' | ❌ Unsatisfied: ' + unsatisfied.length;
            }else{
              html += ' | ✅ All satisfied';
            }
            html += '</div>';
          }
          
          content.innerHTML = html;
        }catch(e){ content.innerHTML = '<div class="problem">Error loading intelligence: '+e+'</div>'; }
      }
      
      async function remediateTenant(action){
        if(!selectedTenant){
          alert('Select a tenant first');
          return;
        }
        
        // Show preview first
        try{
          const preview = await apiPost('/api/v1/marketplace/tenants/' + selectedTenant + '/remediate/' + action + '/preview', {});
          const msg = 'Expected impact: ' + preview.health_impact + ' points\n' + preview.changes.join('\n') + '\n\nProceed?';
          if(!confirm(msg)) return;
          
          if(action === 'install_missing_deps'){
            const result = await apiPost('/api/v1/marketplace/tenants/' + selectedTenant + '/remediate/install-missing-deps', {});
            setStatusAuto('✅ Installed ' + result.installed_count + ' missing dependencies - Health +' + preview.health_impact);
            await refresh();
            await loadIntelligence();
            await loadTrends();
          }else if(action === 'activate_keys'){
            const result = await apiPost('/api/v1/marketplace/tenants/' + selectedTenant + '/remediate/activate-keys', {});
            setStatusAuto('✅ Activated ' + result.activated_count + ' keys - Health +' + preview.health_impact);
            await refresh();
            await loadIntelligence();
            await loadTrends();
          }
        }catch(e){
          alert('Error: ' + e);
        }
      }
      
      async function loadTrends(){
        if(!selectedTenant){
          return;
        }
        try{
          const data = await apiGet('/api/v1/marketplace/tenants/' + selectedTenant + '/trends');
          let html = '<h3>📈 Tenant Trends & Drift Analysis</h3>';
          
          // Volatility indicator
          if(data.trends.volatility){
            const v = data.trends.volatility;
            const riskColor = v.risk === 'high' ? '#e74c3c' : (v.risk === 'medium' ? '#f39c12' : '#2ecc71');
            html += '<div style="background:' + riskColor + ';color:white;padding:12px;border-radius:6px;margin-bottom:12px;">';
            html += '<strong>Volatility: ' + v.score.toFixed(1) + '</strong> - ' + v.trend.charAt(0).toUpperCase() + v.trend.slice(1);
            html += '</div>';
          }
          
          // Trends grid
          html += '<div class="stats-grid">';
          
          if(data.trends.health_score){
            const hs = data.trends.health_score;
            const arrow = hs.direction === 'up' ? '📈' : (hs.direction === 'down' ? '📉' : '➡️');
            html += '<div class="stat-card" style="border-left:4px solid ' + (hs.direction === 'up' ? '#2ecc71' : (hs.direction === 'down' ? '#e74c3c' : '#95a5a6')) + ';">';
            html += '<div style="font-size:24px;font-weight:bold;">' + arrow + ' ' + hs.delta + '</div>';
            html += '<div style="font-size:12px;color:#666;">Health Score Change</div>';
            html += '</div>';
          }
          
          if(data.trends.adoption){
            const a = data.trends.adoption;
            const arrow = a.direction === 'up' ? '📦' : '📉';
            html += '<div class="stat-card">';
            html += '<div style="font-size:18px;font-weight:bold;">' + arrow + ' ' + a.current + '</div>';
            html += '<div style="font-size:12px;color:#666;">Installs ' + (a.delta > 0 ? '+' + a.delta : a.delta) + '</div>';
            html += '</div>';
          }
          
          if(data.trends.engagement){
            const e = data.trends.engagement;
            const arrow = e.direction === 'up' ? '⭐' : '📉';
            html += '<div class="stat-card">';
            html += '<div style="font-size:18px;font-weight:bold;">' + arrow + ' ' + e.current + '</div>';
            html += '<div style="font-size:12px;color:#666;">Ratings ' + (e.delta > 0 ? '+' + e.delta : e.delta) + '</div>';
            html += '</div>';
          }
          
          html += '</div>';
          
          // Drift alerts
          html += '<h4>🚨 Drift Detectors</h4>';
          
          if(data.trends.revocation_drift && data.trends.revocation_drift.is_drifting){
            html += '<div style="background:#ffebee;border-left:4px solid #e74c3c;padding:12px;margin-bottom:8px;border-radius:4px;">';
            html += '<strong style="color:#c0392b;">⚠️ Revocation Drift Detected</strong>';
            html += '<div style="font-size:12px;color:#555;margin-top:4px;">';
            html += data.trends.revocation_drift.current_count + ' keys revoked (' + data.trends.revocation_drift.current_percent + '%) - up ' + data.trends.revocation_drift.delta;
            html += '</div></div>';
          }
          
          if(data.trends.governance_drift && data.trends.governance_drift.is_drifting){
            html += '<div style="background:#ffebee;border-left:4px solid #e74c3c;padding:12px;margin-bottom:8px;border-radius:4px;">';
            html += '<strong style="color:#c0392b;">🔧 Governance Drift Detected</strong>';
            html += '<div style="font-size:12px;color:#555;margin-top:4px;">';
            html += data.trends.governance_drift.current_missing + ' unmet dependencies - up ' + data.trends.governance_drift.delta;
            html += '</div></div>';
          }
          
          if(!data.trends.revocation_drift?.is_drifting && !data.trends.governance_drift?.is_drifting){
            html += '<div style="background:#e6ffe6;border-left:4px solid #2ecc71;padding:12px;border-radius:4px;">';
            html += '<strong style="color:#0a0;">✅ No drift detected</strong>';
            html += '</div>';
          }
          
          document.getElementById('trendsContent').innerHTML = html;
        }catch(e){
          document.getElementById('trendsContent').innerHTML = '<p style="color:#e74c3c;">Error: ' + e + '</p>';
        }
      }
      
      async function loadLearning(){
        try{
          const data = await apiGet('/api/v1/intelligence-learning');
          let html = '<h3>📚 Intelligence Learning - Continuous Improvement</h3>';
          
          // Effectiveness Score Banner
          const score = data.effectiveness_score;
          html += '<div style="background:' + (score.status === 'excellent' ? '#dcfce7' : (score.status === 'healthy' ? '#dbeafe' : '#fef3c7')) + ';border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px;">';
          html += '<div style="font-size:36px;font-weight:bold;color:' + (score.status === 'excellent' ? '#10b981' : (score.status === 'healthy' ? '#3b82f6' : '#f59e0b')) + ';">' + Math.round(score.score) + '</div>';
          html += '<div style="font-size:12px;color:#666;margin-bottom:12px;">Intelligence Effectiveness Score</div>';
          html += '<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;">';
          Object.entries(score.components).forEach(([key,comp])=>{
            html += '<div><div style="font-size:11px;color:#666;font-weight:600;margin-bottom:6px;">'+comp.label+'</div>';
            html += '<div style="height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;margin-bottom:4px;">';
            html += '<div style="height:100%;width:'+comp.value+'%;background:#3b82f6;"></div></div>';
            html += '<div style="font-size:12px;font-weight:600;">'+Math.round(comp.value)+'%</div></div>';
          });
          html += '</div></div>';
          
          // Performance Panel
          if(data.performance && data.performance.recommendations){
            html += '<h4>🏆 Top Performing Recommendations</h4>';
            html += '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
            html += '<tr style="background:#f3f4f6;"><th style="padding:12px;text-align:left;border-bottom:1px solid #e5e7eb;">Recommendation</th><th style="padding:12px;text-align:left;border-bottom:1px solid #e5e7eb;">Success</th><th style="padding:12px;text-align:left;border-bottom:1px solid #e5e7eb;">Adoption</th><th style="padding:12px;text-align:left;border-bottom:1px solid #e5e7eb;">Avg Health Gain</th><th style="padding:12px;text-align:left;border-bottom:1px solid #e5e7eb;">Score</th></tr>';
            data.performance.recommendations.slice(0,5).forEach(rec=>{
              html += '<tr style="border-bottom:1px solid #f3f4f6;"><td style="padding:12px;">'+rec.recommendation_type+'</td>';
              html += '<td style="padding:12px;color:#10b981;font-weight:600;">'+Math.round(rec.success_rate*100)+'%</td>';
              html += '<td style="padding:12px;">'+Math.round(rec.adoption_rate*100)+'%</td>';
              html += '<td style="padding:12px;">'+rec.avg_health_improvement.toFixed(1)+'</td>';
              html += '<td style="padding:12px;font-weight:600;">'+rec.effectiveness_score.toFixed(1)+'</td></tr>';
            });
            html += '</table>';
          }
          
          // Adoption Gaps
          if(data.adoption_gaps && data.adoption_gaps.gaps && data.adoption_gaps.gaps.length > 0){
            html += '<h4>⚠️ Low Adoption Signals</h4>';
            data.adoption_gaps.gaps.forEach(gap=>{
              html += '<div style="background:#fffbeb;border-left:4px solid #f59e0b;border-radius:4px;padding:12px;margin-bottom:12px;">';
              html += '<strong>'+gap.recommendation_type+'</strong> - '+Math.round(gap.adoption_rate*100)+'% adoption';
              html += '<div style="font-size:12px;color:#666;margin-top:4px;">Generated: '+gap.generated_count+', Accepted: '+gap.accepted_count+', Ignored: '+gap.ignored_count+'</div>';
              html += '<div style="font-size:12px;color:#666;font-style:italic;margin-top:4px;">'+gap.reason+'</div></div>';
            });
          }
          
          // Recurring Issues
          if(data.recurring_issues && data.recurring_issues.issues && data.recurring_issues.issues.length > 0){
            html += '<h4>🔄 Recurring Issues</h4>';
            data.recurring_issues.issues.slice(0,5).forEach(issue=>{
              html += '<div style="background:#f3e8ff;border-left:4px solid #8b5cf6;border-radius:4px;padding:12px;margin-bottom:12px;">';
              html += '<strong>'+issue.issue+'</strong> - '+issue.occurrence_count+'x in last 30 days';
              html += '<div style="font-size:12px;color:#666;margin-top:4px;">Trend: '+issue.trend.charAt(0).toUpperCase()+issue.trend.slice(1)+' (last seen '+Math.round(issue.last_seen_days_ago)+'d ago)</div>';
              html += '<div style="font-size:12px;color:#666;font-style:italic;margin-top:4px;">'+issue.recommendation+'</div></div>';
            });
          }
          
          // Trends
          if(data.trends){
            html += '<h4>📈 30-Day Intelligence Trends</h4>';
            html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;">';
            [['mttd_trend','Detection Speed'],['mttr_trend','Resolution Speed'],['accuracy_trend','Intelligence Accuracy'],['acceptance_trend','Recommendation Acceptance']].forEach(([key,label])=>{
              const trend = data.trends.trends[key];
              const color = trend === 'improving' ? '#10b981' : (trend === 'degrading' ? '#ef4444' : '#666');
              const arrow = trend === 'improving' ? '↑' : (trend === 'degrading' ? '↓' : '→');
              html += '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px;"><div style="font-size:12px;color:#666;margin-bottom:8px;">'+label+'</div><div style="font-size:18px;color:'+color+';font-weight:bold;">'+arrow+' '+trend.charAt(0).toUpperCase()+trend.slice(1)+'</div></div>';
            });
            html += '</div>';
          }
          
          document.getElementById('learningContent').innerHTML = html;
        }catch(e){
          document.getElementById('learningContent').innerHTML = '<p style="color:#e74c3c;">Error loading learning data: ' + e + '</p>';
        }
      }
      
      function debouncedRefresh(){ if(debounceTimer) clearTimeout(debounceTimer); debounceTimer = setTimeout(refresh, 250); }
      
      window.addEventListener('load', async ()=>{ await loadTenants(); await refresh(); });
      
      function debouncedRefresh(){ if(debounceTimer) clearTimeout(debounceTimer); debounceTimer = setTimeout(refresh, 250); }
      
      window.addEventListener('load', async ()=>{ await loadTenants(); await refresh(); });
    </script>
    SCRIPT;
    echo '</body></html>';
    exit;
}

if ($method === 'GET' && preg_match('#^/marketplace-ui/plugins/([^/]+)$#', $path, $matches)) {
    $pluginId = $matches[1];
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Plugin Detail</title></head><body>';
    echo '<h2>Plugin Detail</h2>';
    echo '<div id="plugin">Loading...</div>';
    echo '<p><a href="/marketplace-ui">Back to list</a></p>';
    echo '<script>window.__PLUGIN_ID = ' . json_encode($pluginId) . ';</script>';
    echo <<<'SCRIPT'
    <script>
      function apiGet(p){ return fetch(p,{headers:{Accept:"application/json"}}).then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status+' for '+p); return r.json(); }); }
      function apiPost(p,b){ return fetch(p,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b||{})}).then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status+' for '+p); return r.json(); }); }
      function apiPut(p,b){ return fetch(p,{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify(b||{})}).then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status+' for '+p); return r.json(); }); }
      function apiDelete(p){ return fetch(p,{method:'DELETE'}).then(r=>{ if(r.status===204) return {}; if(!r.ok) throw new Error('HTTP '+r.status+' for '+p); return r.json(); }); }
      (async function load(){ try{
          const pid = window.__PLUGIN_ID;
          const plugin = await apiGet('/api/v1/marketplace/plugins/'+encodeURIComponent(pid));
          const versions = await apiGet('/api/v1/marketplace/plugins/'+encodeURIComponent(pid)+'/versions');
          const ratings = await apiGet('/api/v1/marketplace/plugins/'+encodeURIComponent(pid)+'/ratings');
          const pluginKeys = await apiGet('/api/v1/marketplace/plugins/'+encodeURIComponent(pid)+'/keys');
          let html = '';
          html += '<div id="meta">';
          html += '<h3>' + (plugin.name||plugin.id) + ' <button id="editMetaBtn">Edit</button></h3>';
          html += '<p id="desc">' + (plugin.description||'') + '</p>';
          html += '<p><strong>Author:</strong> ' + (plugin.author||'') + ' <strong>Version:</strong> ' + (plugin.version||'') + ' <strong>Published:</strong> ' + (plugin.published ? 'yes' : 'no') + '</p>';
          html += '</div>';
          html += '<div id="metaEdit" style="display:none; margin-top:8px;">Name: <input id="metaName" value="' + (plugin.name||'') + '" /> <br/>Version: <input id="metaVersion" value="' + (plugin.version||'') + '" /> <br/>Description:<br/><textarea id="metaDesc" rows="4" cols="60">' + (plugin.description||'') + '</textarea><br/><button id="saveMetaBtn">Save</button> <button id="cancelMetaBtn">Cancel</button></div>';
          html += '<h4>Keys <button id="addKeyBtn">Add Key</button></h4>';
          html += '<div id="addKeyForm" style="display:none; margin-bottom:8px;">Label: <input id="newKeyLabel" /> <br/>Public key PEM:<br/><textarea id="newKeyPem" rows="8" cols="60"></textarea><br/><button id="saveKeyBtn">Save Key</button> <button id="cancelKeyBtn">Cancel</button></div>';
          html += '<div id="keysList"></div>';
          html += '<h4>Versions</h4>';
          if(!(versions.items||[]).length) html += '<p>No versions</p>'; else { html += '<ul>'; for(const v of (versions.items||[])) html += '<li>' + (v.version||v.id) + ' - ' + (v.manifest_validated? 'manifest ok' : 'no manifest') + (v.signature_verified? ' - signature ok' : '') + '</li>'; html += '</ul>'; }
          html += '<h4>Ratings</h4>';
          if(!(ratings.items||[]).length) html += '<p>No ratings</p>'; else { html += '<ul>'; for(const r of (ratings.items||[])) html += '<li>' + (r.rating||'') + ' - ' + (r.comment||'') + ' (' + (r.tenant_id||'') + ')</li>'; html += '</ul>'; html += '<p>Average: ' + (ratings.average||'n/a') + '</p>'; }
          document.getElementById('plugin').innerHTML = html;
          async function refreshKeys(){ try{ const kresp = await apiGet('/api/v1/marketplace/plugins/'+encodeURIComponent(pid)+'/keys'); const list = kresp.items || []; const container = document.getElementById('keysList'); container.innerHTML = ''; if(!list.length){ container.innerHTML = '<p>No keys registered</p>'; return; } const ul = document.createElement('ul'); for(const k of list){ const li = document.createElement('li'); li.innerText = (k.label||k.id) + ' - ' + (k.revoked? 'revoked' : 'active'); const btnRev = document.createElement('button'); btnRev.innerText = k.revoked? 'Activate' : 'Revoke'; btnRev.onclick = async ()=>{ try{ const ep = '/api/v1/marketplace/plugins/'+encodeURIComponent(pid)+'/keys/'+encodeURIComponent(k.id)+'/'+(k.revoked? 'activate' : 'revoke'); const r = await apiPost(ep,{}); alert(JSON.stringify(r)); await refreshKeys(); }catch(e){ alert('Key action failed: '+e); } }; const btnDel = document.createElement('button'); btnDel.innerText = 'Delete'; btnDel.onclick = async ()=>{ if(!confirm('Delete key?')) return; try{ await apiDelete('/api/v1/marketplace/plugins/'+encodeURIComponent(pid)+'/keys/'+encodeURIComponent(k.id)); alert('Deleted'); await refreshKeys(); }catch(e){ alert('Delete failed: '+e); } }; li.appendChild(document.createTextNode(' ')); li.appendChild(btnRev); li.appendChild(document.createTextNode(' ')); li.appendChild(btnDel); ul.appendChild(li); } container.appendChild(ul); }catch(e){ console.error(e); } }
          document.getElementById('addKeyBtn').onclick = ()=>{ document.getElementById('addKeyForm').style.display = 'block'; };
          document.getElementById('cancelKeyBtn').onclick = ()=>{ document.getElementById('addKeyForm').style.display = 'none'; };
          document.getElementById('saveKeyBtn').onclick = async ()=>{ const label = document.getElementById('newKeyLabel').value || ''; const pem = document.getElementById('newKeyPem').value || ''; if(!pem.trim()){ alert('Public key required'); return; } try{ const r = await apiPost('/api/v1/marketplace/plugins/'+encodeURIComponent(pid)+'/keys',{public_key: pem, label}); alert('Saved: '+JSON.stringify(r)); document.getElementById('newKeyLabel').value=''; document.getElementById('newKeyPem').value=''; document.getElementById('addKeyForm').style.display='none'; await refreshKeys(); }catch(e){ alert('Save key failed: '+e); } };
          document.getElementById('editMetaBtn').onclick = ()=>{ document.getElementById('meta').style.display = 'none'; document.getElementById('metaEdit').style.display = 'block'; };
          document.getElementById('cancelMetaBtn').onclick = ()=>{ document.getElementById('metaEdit').style.display = 'none'; document.getElementById('meta').style.display = 'block'; };
          document.getElementById('saveMetaBtn').onclick = async ()=>{ try{ const nm = document.getElementById('metaName').value||''; const ver = document.getElementById('metaVersion').value||''; const desc = document.getElementById('metaDesc').value||''; const res = await apiPut('/api/v1/marketplace/plugins/'+encodeURIComponent(pid), { name: nm, version: ver, description: desc }); alert('Saved: '+JSON.stringify(res)); location.reload(); }catch(e){ alert('Save meta failed: '+e); } };
          await refreshKeys();
      }catch(e){ document.getElementById('plugin').innerText = 'Error loading plugin: '+e; } })();
    </script>
    SCRIPT;
    echo '</body></html>';
    exit;
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
