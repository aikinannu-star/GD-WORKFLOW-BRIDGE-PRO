<?php
/**
 * Contract Tests for Phase 4: Intelligence Effectiveness Engine
 * Validates that remediation lifecycle is tracked and effectiveness metrics are accurate
 */

class EffectivenessContractTests
{
    private static $testsPassed = 0;
    private static $testsFailed = 0;

    public static function runAll()
    {
        echo "\n=== Phase 4: Intelligence Effectiveness Contract Tests ===\n\n";

        self::testRecommendationEffectivenessStructure();
        self::testMTTDMetrics();
        self::testMTTRMetrics();
        self::testAcceptanceRateMetrics();
        self::testAccuracyMetrics();
        self::testRemediationEventStructure();
        self::testEffectivenessDataIntegrity();
        self::testPercentileMath();

        echo "\n=== Test Summary ===\n";
        echo "Passed: " . self::$testsPassed . "\n";
        echo "Failed: " . self::$testsFailed . "\n";
        $total = self::$testsPassed + self::$testsFailed;
        echo "Success Rate: " . round((self::$testsPassed / max(1, $total)) * 100, 1) . "%\n\n";

        return self::$testsFailed === 0;
    }

    private static function assert($condition, $message)
    {
        if ($condition) {
            echo "  ✓ $message\n";
            self::$testsPassed++;
        } else {
            echo "  ✗ FAIL: $message\n";
            self::$testsFailed++;
        }
    }

    private static function testRecommendationEffectivenessStructure()
    {
        echo "Test: Recommendation Effectiveness Structure\n";
        
        $recs = EffectivenessMetrics::computeRecommendationEffectiveness();
        
        self::assert(is_array($recs), "Returns array");
        if (count($recs) > 0) {
            $first = $recs[0];
            self::assert(isset($first['type']), "Has 'type' field");
            self::assert(isset($first['generated_count']), "Has 'generated_count'");
            self::assert(isset($first['accepted_count']), "Has 'accepted_count'");
            self::assert(isset($first['adoption_rate']), "Has 'adoption_rate'");
            self::assert(isset($first['success_rate']), "Has 'success_rate'");
            self::assert(isset($first['avg_health_improvement']), "Has 'avg_health_improvement'");
            self::assert(isset($first['avg_resolution_hours']), "Has 'avg_resolution_hours'");
            self::assert($first['adoption_rate'] >= 0 && $first['adoption_rate'] <= 1, "adoption_rate is 0..1");
            self::assert($first['success_rate'] >= 0 && $first['success_rate'] <= 1, "success_rate is 0..1");
        }
    }

    private static function testMTTDMetrics()
    {
        echo "\nTest: MTTD (Mean Time To Detect) Metrics\n";
        
        $mttd = EffectivenessMetrics::computeMTTD();
        
        self::assert(isset($mttd['mttd_hours_avg']), "Has 'mttd_hours_avg'");
        self::assert(isset($mttd['mttd_hours_p95']), "Has 'mttd_hours_p95'");
        self::assert(isset($mttd['recent_detections']), "Has 'recent_detections'");
        self::assert(isset($mttd['anomalies_detected_7d']), "Has 'anomalies_detected_7d'");
        self::assert($mttd['mttd_hours_avg'] >= 0, "MTTD avg is non-negative");
        self::assert($mttd['mttd_hours_p95'] >= $mttd['mttd_hours_avg'], "P95 >= avg");
        self::assert($mttd['recent_detections'] >= 0, "Detection count non-negative");
    }

    private static function testMTTRMetrics()
    {
        echo "\nTest: MTTR (Mean Time To Resolve) Metrics\n";
        
        $mttr = EffectivenessMetrics::computeMTTR();
        
        self::assert(isset($mttr['mttr_hours_avg']), "Has 'mttr_hours_avg'");
        self::assert(isset($mttr['mttr_hours_p95']), "Has 'mttr_hours_p95'");
        self::assert(isset($mttr['resolved_count_7d']), "Has 'resolved_count_7d'");
        self::assert(isset($mttr['unresolved_count']), "Has 'unresolved_count'");
        self::assert($mttr['mttr_hours_avg'] >= 0, "MTTR avg is non-negative");
        self::assert($mttr['mttr_hours_p95'] >= $mttr['mttr_hours_avg'], "P95 >= avg");
        self::assert($mttr['resolved_count_7d'] >= 0, "Resolved count non-negative");
    }

    private static function testAcceptanceRateMetrics()
    {
        echo "\nTest: Recommendation Acceptance Rate\n";
        
        $rate = EffectivenessMetrics::computeAcceptanceRate();
        
        self::assert(isset($rate['overall_acceptance_rate']), "Has 'overall_acceptance_rate'");
        self::assert(isset($rate['by_type']), "Has 'by_type'");
        self::assert(isset($rate['trend_7d']), "Has 'trend_7d'");
        self::assert(isset($rate['trend_30d']), "Has 'trend_30d'");
        self::assert($rate['overall_acceptance_rate'] >= 0 && $rate['overall_acceptance_rate'] <= 1, "Rate is 0..1");
        self::assert($rate['trend_7d'] >= 0 && $rate['trend_7d'] <= 1, "7d trend is 0..1");
        self::assert(is_array($rate['by_type']), "by_type is array");
    }

    private static function testAccuracyMetrics()
    {
        echo "\nTest: Intelligence Accuracy Metrics\n";
        
        $acc = EffectivenessMetrics::computeAccuracy();
        
        self::assert(isset($acc['detected_anomalies_7d']), "Has 'detected_anomalies_7d'");
        self::assert(isset($acc['confirmed_true_anomalies']), "Has 'confirmed_true_anomalies'");
        self::assert(isset($acc['false_positives']), "Has 'false_positives'");
        self::assert(isset($acc['precision']), "Has 'precision'");
        self::assert(isset($acc['false_positive_rate']), "Has 'false_positive_rate'");
        self::assert($acc['precision'] >= 0 && $acc['precision'] <= 1, "Precision is 0..1");
        self::assert($acc['false_positive_rate'] >= 0 && $acc['false_positive_rate'] <= 1, "FP rate is 0..1");
        self::assert($acc['confirmed_true_anomalies'] <= $acc['detected_anomalies_7d'], "Confirmed <= detected");
    }

    private static function testRemediationEventStructure()
    {
        echo "\nTest: Remediation Event Structure & Lifecycle\n";
        
        $events = getPlatformRemediationEvents();
        
        self::assert(is_array($events), "Events is array");
        
        if (count($events) > 0) {
            $ev = $events[0];
            self::assert(isset($ev['id']), "Event has 'id'");
            self::assert(isset($ev['tenant_id']), "Event has 'tenant_id'");
            self::assert(isset($ev['action']), "Event has 'action'");
            self::assert(isset($ev['recommendation_type']), "Event has 'recommendation_type'");
            self::assert(isset($ev['created_at']), "Event has 'created_at'");
            self::assert(isset($ev['details']), "Event has 'details'");
            self::assert(is_array($ev['details']), "Details is object/array");
        }
    }

    private static function testEffectivenessDataIntegrity()
    {
        echo "\nTest: Effectiveness Data Integrity\n";
        
        $events = getPlatformRemediationEvents();
        $recs = EffectivenessMetrics::computeRecommendationEffectiveness($events);
        
        // Sum of all recommendation counts should match total events
        $totalCount = 0;
        foreach ($recs as $r) {
            $totalCount += $r['generated_count'];
        }
        
        self::assert($totalCount === count($events), "Recommendation counts sum to total events ($totalCount == " . count($events) . ")");
        
        // Acceptance should not exceed generated
        foreach ($recs as $r) {
            self::assert($r['accepted_count'] <= $r['generated_count'], 
                "Accepted <= Generated for {$r['type']}");
        }
    }

    private static function testPercentileMath()
    {
        echo "\nTest: Percentile Calculations\n";
        
        $mttr = EffectivenessMetrics::computeMTTR();
        
        // P95 should be >= average
        self::assert($mttr['mttr_hours_p95'] >= $mttr['mttr_hours_avg'],
            "P95 MTTR >= avg MTTR ({$mttr['mttr_hours_p95']} >= {$mttr['mttr_hours_avg']})");
        
        $mttd = EffectivenessMetrics::computeMTTD();
        self::assert($mttd['mttd_hours_p95'] >= $mttd['mttd_hours_avg'],
            "P95 MTTD >= avg MTTD");
    }
}

// Run tests if this file is invoked directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    chdir(__DIR__ . '/..');
    require_once 'services/lib/ServiceHelpers.php';
    require_once 'services/marketplace/TimeSeriesHelper.php';
    require_once 'services/marketplace/EffectivenessMetrics.php';
    
    // Stub the helper functions needed for EffectivenessMetrics
    if (!function_exists('getPlatformRemediationEvents')) {
        function getPlatformRemediationEvents() {
            $events = ServiceHelpers::loadJson('marketplace', 'remediation_events.json');
            return is_array($events) ? $events : [];
        }
    }
    
    $success = EffectivenessContractTests::runAll();
    exit($success ? 0 : 1);
}
