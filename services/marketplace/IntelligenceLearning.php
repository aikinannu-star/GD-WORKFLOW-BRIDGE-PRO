<?php
/**
 * Intelligence Learning Analytics
 * 
 * Provides insights about recommendation effectiveness, operator behavior,
 * recurring issues, and system improvement trends.
 * 
 * Four core insights:
 * 1. Recommendation Performance - ranked by success, health gain, resolution time
 * 2. Low Adoption Signals - recommendations operators consistently ignore
 * 3. Recurring Issues - problems that repeat across the fleet
 * 4. Intelligence Trends - whether the system itself is improving
 * 5. Effectiveness Score - composite 0-100 metric for executive visibility
 */

require_once __DIR__ . '/EffectivenessMetrics.php';
require_once __DIR__ . '/../lib/ServiceHelpers.php';

class IntelligenceLearning {
  private $effectivenessMetrics;

  public function __construct() {
    $this->effectivenessMetrics = new EffectivenessMetrics();
  }

  /**
   * Compute recommendation performance ranking
   * 
   * Ranks recommendations by:
   * - Success rate (primary)
   * - Average health improvement
   * - Average resolution time
   * 
   * Returns array of recommendations with metrics, sorted by effectiveness
   */
  public function computeRecommendationPerformance() {
    $recommendations = $this->effectivenessMetrics->computeRecommendationEffectiveness();
    
    if (empty($recommendations['by_type'])) {
      return [
        'recommendations' => [],
        'total_types' => 0,
        'computed_at' => date('c'),
      ];
    }

    $ranked = [];
    foreach ($recommendations['by_type'] as $type => $data) {
      $ranked[] = [
        'recommendation_type' => $type,
        'success_rate' => $data['success_rate'],
        'adoption_rate' => $data['adoption_rate'],
        'avg_health_improvement' => $data['avg_health_improvement'] ?? 0,
        'avg_resolution_hours' => $data['avg_resolution_hours'] ?? 0,
        'generated_count' => $data['generated_count'] ?? 0,
        'accepted_count' => $data['accepted_count'] ?? 0,
        'executed_count' => $data['executed_count'] ?? 0,
        'success_count' => $data['success_count'] ?? 0,
        'effectiveness_score' => $this->computeRecommendationScore($data),
      ];
    }

    // Sort by effectiveness score (descending)
    usort($ranked, function($a, $b) {
      return $b['effectiveness_score'] <=> $a['effectiveness_score'];
    });

    return [
      'recommendations' => $ranked,
      'total_types' => count($ranked),
      'top_performer' => $ranked[0]['recommendation_type'] ?? null,
      'top_performer_score' => $ranked[0]['effectiveness_score'] ?? 0,
      'computed_at' => date('c'),
    ];
  }

  /**
   * Identify recommendations with low adoption (< 70%)
   * 
   * Surface recommendations that are frequently generated but ignored,
   * indicating either poor recommendation quality or unclear operator workflows.
   */
  public function computeAdoptionGaps() {
    $recommendations = $this->effectivenessMetrics->computeRecommendationEffectiveness();
    
    if (empty($recommendations['by_type'])) {
      return [
        'gaps' => [],
        'total_gaps' => 0,
        'avg_adoption_gap' => 0,
        'computed_at' => date('c'),
      ];
    }

    $gaps = [];
    $totalGap = 0;
    $count = 0;

    foreach ($recommendations['by_type'] as $type => $data) {
      $adoptionRate = $data['adoption_rate'] ?? 0;
      
      // Flag recommendations with < 70% adoption
      if ($adoptionRate < 0.70) {
        $generatedCount = $data['generated_count'] ?? 0;
        $acceptedCount = $data['accepted_count'] ?? 0;
        $ignoredCount = $generatedCount - $acceptedCount;

        $gaps[] = [
          'recommendation_type' => $type,
          'adoption_rate' => $adoptionRate,
          'adoption_percentage' => round($adoptionRate * 100, 1),
          'generated_count' => $generatedCount,
          'accepted_count' => $acceptedCount,
          'ignored_count' => $ignoredCount,
          'severity' => $adoptionRate < 0.40 ? 'critical' : ($adoptionRate < 0.60 ? 'warning' : 'advisory'),
          'reason' => $this->inferAdoptionReason($type, $data),
        ];

        $totalGap += (1.0 - $adoptionRate);
        $count++;
      }
    }

    // Sort by adoption gap (largest gap first)
    usort($gaps, function($a, $b) {
      return $a['adoption_rate'] <=> $b['adoption_rate'];
    });

    return [
      'gaps' => $gaps,
      'total_gaps' => count($gaps),
      'avg_adoption_gap' => $count > 0 ? $totalGap / $count : 0,
      'critical_count' => count(array_filter($gaps, fn($g) => $g['severity'] === 'critical')),
      'recommendations' => [
        'review_quality' => count($gaps) > 0 ? 'Recommendations with low adoption may need quality review or context improvement' : null,
        'improve_workflow' => count($gaps) > 2 ? 'Consider operator workflow or automation improvements' : null,
      ],
      'computed_at' => date('c'),
    ];
  }

  /**
   * Identify recurring issues across the fleet
   * 
   * Aggregate issues that occur repeatedly, indicating systematic problems
   * that may deserve engineering investment rather than repeated remediation.
   */
  public function computeRecurringIssues() {
    $events = ServiceHelpers::loadJson('marketplace', 'remediation_events.json', []);
    
    if (empty($events)) {
      return [
        'issues' => [],
        'total_recurring' => 0,
        'computed_at' => date('c'),
      ];
    }

    // Count occurrences of each action type in last 30 days
    $thirtyDaysAgo = time() - (30 * 24 * 60 * 60);
    $actionCounts = [];
    $actionTrends = []; // Track first and last occurrence

    foreach ($events as $event) {
      $createdAt = strtotime($event['created_at'] ?? 'now');
      if ($createdAt < $thirtyDaysAgo) {
        continue;
      }

      $action = $event['action'] ?? 'unknown';
      $actionCounts[$action] = ($actionCounts[$action] ?? 0) + 1;

      if (!isset($actionTrends[$action])) {
        $actionTrends[$action] = ['first' => $createdAt, 'last' => $createdAt];
      } else {
        $actionTrends[$action]['last'] = max($actionTrends[$action]['last'], $createdAt);
      }
    }

    // Filter for recurring (> 3 occurrences)
    $recurring = [];
    foreach ($actionCounts as $action => $count) {
      if ($count >= 3) {
        $firstOccurrence = $actionTrends[$action]['first'];
        $lastOccurrence = $actionTrends[$action]['last'];
        $daysSinceFirst = (time() - $firstOccurrence) / (24 * 60 * 60);
        $daysSinceLast = (time() - $lastOccurrence) / (24 * 60 * 60);

        // Determine trend direction
        $trend = 'stable';
        if ($daysSinceLast < 7) {
          $trend = 'increasing';
        } elseif ($daysSinceLast > 14) {
          $trend = 'decreasing';
        }

        $recurring[] = [
          'issue' => $action,
          'occurrence_count' => $count,
          'first_seen_days_ago' => round($daysSinceFirst, 1),
          'last_seen_days_ago' => round($daysSinceLast, 1),
          'trend' => $trend,
          'severity' => $count >= 10 ? 'critical' : ($count >= 6 ? 'warning' : 'advisory'),
          'recommendation' => $this->inferIssueRecommendation($action, $count),
        ];
      }
    }

    // Sort by occurrence count (descending)
    usort($recurring, function($a, $b) {
      return $b['occurrence_count'] <=> $a['occurrence_count'];
    });

    return [
      'issues' => $recurring,
      'total_recurring' => count($recurring),
      'critical_issues' => count(array_filter($recurring, fn($i) => $i['severity'] === 'critical')),
      'computed_at' => date('c'),
    ];
  }

  /**
   * Compute intelligence trends over 30 days
   * 
   * Shows whether the intelligence layer is improving by comparing
   * metrics in 0-7 days vs 7-14 days vs 14-30 days.
   */
  public function computeIntelligenceTrends() {
    // Get historical data
    $tenantHistory = glob(ServiceHelpers::dataPath('marketplace', 'tenant_history_*.json'));
    
    // Compute metrics for three time windows
    $periods = [
      'current' => ['label' => '0-7 days', 'days' => 7],
      'previous' => ['label' => '7-14 days', 'days' => 7],
      'baseline' => ['label' => '14-30 days', 'days' => 16],
    ];

    $trends = [];
    foreach ($periods as $key => $period) {
      $trends[$key] = $this->computeMetricsForPeriod($period['days'], $period['label']);
    }

    // Compute trend directions
    $trend_analysis = [
      'mttd_trend' => $this->computeTrendDirection(
        $trends['current']['mttd_hours_avg'] ?? 0,
        $trends['previous']['mttd_hours_avg'] ?? 0
      ),
      'mttr_trend' => $this->computeTrendDirection(
        $trends['current']['mttr_hours_avg'] ?? 0,
        $trends['previous']['mttr_hours_avg'] ?? 0
      ),
      'accuracy_trend' => $this->computeTrendDirection(
        $trends['current']['accuracy_precision'] ?? 0,
        $trends['previous']['accuracy_precision'] ?? 0,
        false // higher is better for accuracy
      ),
      'acceptance_trend' => $this->computeTrendDirection(
        $trends['current']['acceptance_rate'] ?? 0,
        $trends['previous']['acceptance_rate'] ?? 0,
        false // higher is better for acceptance
      ),
    ];

    return [
      'periods' => $trends,
      'trends' => $trend_analysis,
      'overall_direction' => $this->computeOverallTrendDirection($trend_analysis),
      'computed_at' => date('c'),
    ];
  }

  /**
   * Compute Intelligence Effectiveness Score (0-100)
   * 
   * Weighted composite metric combining:
   * - 30% Accuracy (precision)
   * - 25% Acceptance Rate
   * - 20% Fleet Stability (inverse of anomalies)
   * - 15% MTTR
   * - 10% False Positive Rate
   */
  public function computeEffectivenessScore() {
    $metrics = $this->effectivenessMetrics->computeRecommendationEffectiveness();
    $accuracy = $this->effectivenessMetrics->computeAccuracy();
    $mttd = $this->effectivenessMetrics->computeMTTD();
    $mttr = $this->effectivenessMetrics->computeMTTR();
    $acceptance = $this->effectivenessMetrics->computeAcceptanceRate();

    // Extract values
    $accuracyScore = ($accuracy['precision'] ?? 0) * 100;
    $acceptanceScore = ($acceptance['overall_acceptance_rate'] ?? 0) * 100;
    $fpRateScore = max(0, 100 - (($accuracy['false_positive_rate'] ?? 0) * 100));
    $mttrScore = max(0, 100 - ($mttr['mttr_hours_avg'] ?? 0) * 5); // 20h = 0, 0h = 100
    $fleetStabilityScore = 70; // Placeholder: compute from drift analysis if available

    // Weighted composite
    $score = (
      ($accuracyScore * 0.30) +
      ($acceptanceScore * 0.25) +
      ($fleetStabilityScore * 0.20) +
      ($mttrScore * 0.15) +
      ($fpRateScore * 0.10)
    );

    // Cap at 100
    $score = min(100, max(0, $score));

    // Determine health status
    $status = 'critical';
    if ($score >= 85) {
      $status = 'excellent';
    } elseif ($score >= 75) {
      $status = 'healthy';
    } elseif ($score >= 60) {
      $status = 'warning';
    }

    return [
      'score' => round($score, 1),
      'status' => $status,
      'components' => [
        'accuracy' => [
          'weight' => 0.30,
          'value' => round($accuracyScore, 1),
          'label' => 'Precision',
        ],
        'acceptance' => [
          'weight' => 0.25,
          'value' => round($acceptanceScore, 1),
          'label' => 'Recommendation Acceptance',
        ],
        'fleet_stability' => [
          'weight' => 0.20,
          'value' => round($fleetStabilityScore, 1),
          'label' => 'Fleet Stability',
        ],
        'mttr' => [
          'weight' => 0.15,
          'value' => round($mttrScore, 1),
          'label' => 'Resolution Speed',
        ],
        'false_positives' => [
          'weight' => 0.10,
          'value' => round($fpRateScore, 1),
          'label' => 'False Positive Control',
        ],
      ],
      'trend' => 'stable', // Can be computed from historical scores
      'computed_at' => date('c'),
    ];
  }

  /**
   * Get consolidated learning data (all panels)
   */
  public function getConsolidatedLearning() {
    return [
      'performance' => $this->computeRecommendationPerformance(),
      'adoption_gaps' => $this->computeAdoptionGaps(),
      'recurring_issues' => $this->computeRecurringIssues(),
      'trends' => $this->computeIntelligenceTrends(),
      'effectiveness_score' => $this->computeEffectivenessScore(),
      'computed_at' => date('c'),
    ];
  }

  // ────────────────────────────────────────────────────────────────────────
  // PRIVATE HELPERS
  // ────────────────────────────────────────────────────────────────────────

  private function computeRecommendationScore($data) {
    // Score based on success rate, adoption, and health improvement
    $successScore = ($data['success_rate'] ?? 0) * 50;
    $adoptionScore = ($data['adoption_rate'] ?? 0) * 30;
    $healthScore = min(20, (($data['avg_health_improvement'] ?? 0) / 100) * 20);
    
    return $successScore + $adoptionScore + $healthScore;
  }

  private function inferAdoptionReason($type, $data) {
    $adoptionRate = $data['adoption_rate'] ?? 0;
    
    if ($adoptionRate < 0.30) {
      return 'Rarely adopted - likely poor recommendation quality or unclear value';
    } elseif ($adoptionRate < 0.50) {
      return 'Often ignored - may indicate automation opportunity or need for improvement';
    } else {
      return 'Sometimes skipped - consider operator feedback and workflow impact';
    }
  }

  private function inferIssueRecommendation($action, $count) {
    if ($count >= 10) {
      return 'This issue recurs frequently - consider engineering investment to address root cause';
    } elseif ($count >= 6) {
      return 'This issue repeats regularly - monitor for patterns and consider automation';
    } else {
      return 'This issue appears occasionally - track if patterns emerge';
    }
  }

  private function computeMetricsForPeriod($days, $label) {
    $startTime = time() - ($days * 24 * 60 * 60);
    
    // Simplified: return sample data structure
    // In production, compute from actual events in time window
    return [
      'period' => $label,
      'mttd_hours_avg' => 2.5,
      'mttr_hours_avg' => 4.0,
      'accuracy_precision' => 0.82,
      'acceptance_rate' => 0.87,
    ];
  }

  private function computeTrendDirection($current, $previous, $lowerIsBetter = true) {
    if ($current == $previous) {
      return 'stable';
    }
    
    if ($lowerIsBetter) {
      return $current < $previous ? 'improving' : 'degrading';
    } else {
      return $current > $previous ? 'improving' : 'degrading';
    }
  }

  private function computeOverallTrendDirection($trendAnalysis) {
    $improving = 0;
    $degrading = 0;
    
    foreach ($trendAnalysis as $trend) {
      if ($trend === 'improving') {
        $improving++;
      } elseif ($trend === 'degrading') {
        $degrading++;
      }
    }
    
    if ($improving > $degrading) {
      return 'improving';
    } elseif ($degrading > $improving) {
      return 'degrading';
    } else {
      return 'stable';
    }
  }
}
