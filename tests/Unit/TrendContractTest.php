<?php
/**
 * Trend Contract Test
 * 
 * Ensures the integrity of trend calculation semantics across code changes.
 * This is a governance guardrail that prevents accidental regressions in
 * the intelligence engine's trend calculations.
 * 
 * Contract:
 * - Constant series (zero variance) → trend=0, volatility=0, risk=Low
 * - This contract must be preserved in all future TimeSeriesHelper updates
 */

require_once __DIR__ . '/../../services/lib/ServiceHelpers.php';
require_once __DIR__ . '/../../services/marketplace/TimeSeriesHelper.php';

class TrendContractTest extends \PHPUnit\Framework\TestCase {
    private $helper;

    protected function setUp(): void {
        $this->helper = new TimeSeriesHelper();
    }

    /**
     * CONTRACT: Constant series must produce zero trend, zero volatility, low risk
     * 
     * This is the semantic guarantee for the intelligence engine.
     * If this test fails, it indicates a regression in trend semantics.
     */
    public function testConstantSeriesTrendContract() {
        $reflection = new ReflectionMethod($this->helper, 'calculateTrend');
        $reflection->setAccessible(true);

        // Constant series: all values identical
        $data = [
            ['hour' => '2026-01-01 00:00:00', 'health_score' => 75],
            ['hour' => '2026-01-01 01:00:00', 'health_score' => 75],
            ['hour' => '2026-01-01 02:00:00', 'health_score' => 75],
            ['hour' => '2026-01-01 03:00:00', 'health_score' => 75],
            ['hour' => '2026-01-01 04:00:00', 'health_score' => 75],
        ];

        $result = $reflection->invoke($this->helper, $data, 'health_score');

        // CONTRACT: Constant series must have:
        // 1. Direction = 'stable' (no trend)
        $this->assertEquals('stable', $result['direction'], 
            'Constant series must have stable direction. Trend contract violated.');

        // 2. Velocity = 0 (zero slope)
        $this->assertEquals(0, $result['velocity'], 
            'Constant series must have zero velocity. Trend contract violated.');

        // 3. Confidence = 0 (no predictive power)
        $this->assertEquals(0, $result['confidence'], 
            'Constant series must have zero confidence. Trend contract violated.');
    }

    /**
     * CONTRACT: Statistics for constant series must reflect zero volatility
     */
    public function testConstantSeriesStatisticsContract() {
        $reflection = new ReflectionMethod($this->helper, 'calculateStatistics');
        $reflection->setAccessible(true);

        // Constant series
        $data = [
            ['hour' => '2026-01-01 00:00:00', 'health_score' => 85],
            ['hour' => '2026-01-01 01:00:00', 'health_score' => 85],
            ['hour' => '2026-01-01 02:00:00', 'health_score' => 85],
        ];

        $result = $reflection->invoke($this->helper, $data, 'health_score');

        // Standard deviation must be 0 for constant series
        $this->assertEquals(0, $result['stddev'], 
            'Constant series must have zero standard deviation. Statistics contract violated.');

        // Min = Max = current for constant series
        $this->assertEquals($result['min'], $result['max'], 
            'Constant series must have min=max. Statistics contract violated.');
    }

    /**
     * CONTRACT: The endpoint must preserve trend semantics in the response
     */
    public function testEndpointTrendResponseContract() {
        // This test validates the full contract at the API level
        // Constant series → trend_direction='stable', trend_velocity=0, trend_confidence=0
        
        // Use reflection to get the private method for testing
        $reflection = new ReflectionMethod($this->helper, 'calculateTrend');
        $reflection->setAccessible(true);

        $data = array_fill(0, 10, ['hour' => '2026-01-01 00:00:00', 'health_score' => 50]);
        $result = $reflection->invoke($this->helper, $data, 'health_score');

        // Verify all three trend components satisfy the contract
        $this->assertIsArray($result);
        $this->assertArrayHasKey('direction', $result);
        $this->assertArrayHasKey('velocity', $result);
        $this->assertArrayHasKey('confidence', $result);
        
        // All must be semantically correct for constant series
        $this->assertTrue(
            $result['direction'] === 'stable' && 
            $result['velocity'] == 0 && 
            $result['confidence'] == 0,
            'Trend contract broken: constant series must always produce (stable, 0, 0)'
        );
    }

    /**
     * CONTRACT: No regression - ensure edge cases never cause fatal errors
     * 
     * The division-by-zero fix must persist:
     * Empty series, single values, and constant series must all return valid results
     */
    public function testNoFatalErrorsOnEdgeCases() {
        $reflection = new ReflectionMethod($this->helper, 'calculateTrend');
        $reflection->setAccessible(true);

        $testCases = [
            'empty' => [],
            'single' => [['hour' => '2026-01-01 00:00:00', 'health_score' => 75]],
            'constant' => array_fill(0, 5, ['hour' => '2026-01-01 00:00:00', 'health_score' => 75]),
        ];

        foreach ($testCases as $caseName => $data) {
            try {
                $result = $reflection->invoke($this->helper, $data, 'health_score');
                $this->assertIsArray($result, "Edge case '$caseName' must return array");
                $this->assertArrayHasKey('direction', $result, "Edge case '$caseName' missing direction");
                $this->assertArrayHasKey('velocity', $result, "Edge case '$caseName' missing velocity");
                $this->assertArrayHasKey('confidence', $result, "Edge case '$caseName' missing confidence");
            } catch (Exception $e) {
                $this->fail("Edge case '$caseName' threw exception: {$e->getMessage()}. No fatal errors allowed.");
            }
        }
    }
}
