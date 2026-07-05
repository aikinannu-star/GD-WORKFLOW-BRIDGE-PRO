<?php
/**
 * Edge-case regression tests for TimeSeriesHelper trend calculation
 * Ensures robustness against zero-variance, single-value, and empty series
 */

require_once __DIR__ . '/../../services/lib/ServiceHelpers.php';
require_once __DIR__ . '/../../services/marketplace/TimeSeriesHelper.php';

class TimeSeriesHelperEdgeCasesTest extends \PHPUnit\Framework\TestCase {
    private $helper;

    protected function setUp(): void {
        $this->helper = new TimeSeriesHelper();
    }

    /**
     * Test: constant series (zero variance)
     * Expects: stable direction, zero velocity, zero confidence
     */
    public function testConstantSeriesTrendCalculation() {
        $reflection = new ReflectionMethod($this->helper, 'calculateTrend');
        $reflection->setAccessible(true);

        $data = [
            ['hour' => '2026-01-01 00:00:00', 'health_score' => 75],
            ['hour' => '2026-01-01 01:00:00', 'health_score' => 75],
            ['hour' => '2026-01-01 02:00:00', 'health_score' => 75],
        ];

        $result = $reflection->invoke($this->helper, $data, 'health_score');

        $this->assertIsArray($result);
        $this->assertEquals('stable', $result['direction']);
        $this->assertEquals(0, $result['velocity']);
        $this->assertEquals(0, $result['confidence']);
    }

    /**
     * Test: single value series
     * Expects: stable direction, zero velocity, zero confidence (insufficient data)
     */
    public function testSingleValueSeries() {
        $reflection = new ReflectionMethod($this->helper, 'calculateTrend');
        $reflection->setAccessible(true);

        $data = [
            ['hour' => '2026-01-01 00:00:00', 'health_score' => 80],
        ];

        $result = $reflection->invoke($this->helper, $data, 'health_score');

        $this->assertIsArray($result);
        $this->assertEquals('stable', $result['direction']);
        $this->assertEquals(0, $result['velocity']);
        $this->assertEquals(0, $result['confidence']);
    }

    /**
     * Test: empty series
     * Expects: stable direction, zero velocity, zero confidence (no data)
     */
    public function testEmptySeries() {
        $reflection = new ReflectionMethod($this->helper, 'calculateTrend');
        $reflection->setAccessible(true);

        $data = [];

        $result = $reflection->invoke($this->helper, $data, 'health_score');

        $this->assertIsArray($result);
        $this->assertEquals('stable', $result['direction']);
        $this->assertEquals(0, $result['velocity']);
        $this->assertEquals(0, $result['confidence']);
    }

    /**
     * Test: increasing trend
     * Expects: improving direction, positive velocity, high confidence
     */
    public function testIncreasingTrend() {
        $reflection = new ReflectionMethod($this->helper, 'calculateTrend');
        $reflection->setAccessible(true);

        $data = [
            ['hour' => '2026-01-01 00:00:00', 'health_score' => 60],
            ['hour' => '2026-01-01 01:00:00', 'health_score' => 65],
            ['hour' => '2026-01-01 02:00:00', 'health_score' => 70],
            ['hour' => '2026-01-01 03:00:00', 'health_score' => 75],
        ];

        $result = $reflection->invoke($this->helper, $data, 'health_score');

        $this->assertIsArray($result);
        $this->assertEquals('improving', $result['direction']);
        $this->assertGreaterThan(0, $result['velocity']);
        $this->assertGreaterThan(0.8, $result['confidence']);
    }

    /**
     * Test: decreasing trend
     * Expects: degrading direction, negative velocity, high confidence
     */
    public function testDecreasingTrend() {
        $reflection = new ReflectionMethod($this->helper, 'calculateTrend');
        $reflection->setAccessible(true);

        $data = [
            ['hour' => '2026-01-01 00:00:00', 'health_score' => 90],
            ['hour' => '2026-01-01 01:00:00', 'health_score' => 80],
            ['hour' => '2026-01-01 02:00:00', 'health_score' => 70],
            ['hour' => '2026-01-01 03:00:00', 'health_score' => 60],
        ];

        $result = $reflection->invoke($this->helper, $data, 'health_score');

        $this->assertIsArray($result);
        $this->assertEquals('degrading', $result['direction']);
        $this->assertLessThan(0, $result['velocity']);
        $this->assertGreaterThan(0.8, $result['confidence']);
    }

    /**
     * Test: low variance series
     * Expects: stable direction, very low velocity, moderate-to-low confidence
     */
    public function testLowVarianceSeries() {
        $reflection = new ReflectionMethod($this->helper, 'calculateTrend');
        $reflection->setAccessible(true);

        $data = [
            ['hour' => '2026-01-01 00:00:00', 'health_score' => 72],
            ['hour' => '2026-01-01 01:00:00', 'health_score' => 73],
            ['hour' => '2026-01-01 02:00:00', 'health_score' => 71],
            ['hour' => '2026-01-01 03:00:00', 'health_score' => 74],
        ];

        $result = $reflection->invoke($this->helper, $data, 'health_score');

        $this->assertIsArray($result);
        $this->assertEquals('stable', $result['direction']);
        $this->assertLessThan(0.5, abs($result['velocity']));
        $this->assertLessThan(0.5, $result['confidence']);
    }

    /**
     * Test: two-value series (minimal trend)
     * Expects: detectable direction, low-to-moderate velocity
     */
    public function testTwoValueSeries() {
        $reflection = new ReflectionMethod($this->helper, 'calculateTrend');
        $reflection->setAccessible(true);

        $data = [
            ['hour' => '2026-01-01 00:00:00', 'health_score' => 50],
            ['hour' => '2026-01-01 01:00:00', 'health_score' => 75],
        ];

        $result = $reflection->invoke($this->helper, $data, 'health_score');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('direction', $result);
        $this->assertArrayHasKey('velocity', $result);
        $this->assertArrayHasKey('confidence', $result);
        // With only 2 points, direction is improving, but confidence is limited
        $this->assertEquals('improving', $result['direction']);
    }
}
