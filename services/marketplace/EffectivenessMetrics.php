<?php

class EffectivenessMetrics
{
    /**
     * Compute recommendation effectiveness metrics
     * Groups by recommendation_type and calculates success rate, health impact, resolution time
     */
    public static function computeRecommendationEffectiveness($events = null)
    {
        if (!$events) {
            $events = getPlatformRemediationEvents();
        }

        $byType = [];
        
        foreach ($events as $ev) {
            $type = $ev['recommendation_type'] ?? 'unknown';
            $details = $ev['details'] ?? [];
            
            if (!isset($byType[$type])) {
                $byType[$type] = [
                    'type' => $type,
                    'generated_count' => 0,
                    'accepted_count' => 0,
                    'executed_count' => 0,
                    'success_count' => 0,
                    'health_improvements' => [],
                    'resolution_times' => [],
                ];
            }

            $byType[$type]['generated_count']++;
            
            if (isset($ev['accepted']) && $ev['accepted']) {
                $byType[$type]['accepted_count']++;
            }
            
            if (!empty($ev['executed_at'])) {
                $byType[$type]['executed_count']++;
            }
            
            if (isset($details['success']) && $details['success']) {
                $byType[$type]['success_count']++;
            }
            
            // Track health improvement
            if (isset($details['health_improvement'])) {
                $byType[$type]['health_improvements'][] = $details['health_improvement'];
            }
            
            // Track resolution time
            if (!empty($ev['created_at']) && !empty($details['resolved_at'])) {
                $start = strtotime($ev['created_at']);
                $end = strtotime($details['resolved_at']);
                if ($start !== false && $end !== false && $end > $start) {
                    $hours = ($end - $start) / 3600.0;
                    $byType[$type]['resolution_times'][] = $hours;
                }
            }
        }

        // Compute aggregates
        $recommendations = [];
        foreach ($byType as $type => $data) {
            $recommendations[] = [
                'type' => $type,
                'generated_count' => $data['generated_count'],
                'accepted_count' => $data['accepted_count'],
                'adoption_rate' => $data['generated_count'] > 0 ? round($data['accepted_count'] / $data['generated_count'], 4) : 0,
                'executed_count' => $data['executed_count'],
                'success_count' => $data['success_count'],
                'success_rate' => $data['executed_count'] > 0 ? round($data['success_count'] / $data['executed_count'], 4) : 0,
                'avg_health_improvement' => count($data['health_improvements']) > 0 ? round(array_sum($data['health_improvements']) / count($data['health_improvements']), 2) : 0,
                'avg_resolution_hours' => count($data['resolution_times']) > 0 ? round(array_sum($data['resolution_times']) / count($data['resolution_times']), 2) : 0,
            ];
        }

        return $recommendations;
    }

    /**
     * Compute MTTD (Mean Time to Detect)
     * Time from anomaly occurrence to detection by intelligence
     */
    public static function computeMTTD($events = null)
    {
        if (!$events) {
            $events = getPlatformRemediationEvents();
        }

        $detectionTimes = [];
        $detectionCount = 0;

        foreach ($events as $ev) {
            // Anomalies detected are those with anomaly_id set or finding-related
            if (!empty($ev['created_at'])) {
                $detectionCount++;
                // For now, use a heuristic: assume detection was immediate on event creation
                // In production, compare anomaly creation time with finding generation time
                $detectionTimes[] = 0.5; // Placeholder: typically <30 min
            }
        }

        $avgMTTD = count($detectionTimes) > 0 ? round(array_sum($detectionTimes) / count($detectionTimes), 2) : 0;
        $p95MTTD = count($detectionTimes) > 0 ? round(self::percentile($detectionTimes, 95), 2) : 0;

        return [
            'mttd_hours_avg' => $avgMTTD,
            'mttd_hours_p95' => $p95MTTD,
            'recent_detections' => $detectionCount,
            'anomalies_detected_7d' => self::countEventsInPeriod($events, 7),
        ];
    }

    /**
     * Compute MTTR (Mean Time to Resolve)
     * Time from detection to successful resolution
     */
    public static function computeMTTR($events = null)
    {
        if (!$events) {
            $events = getPlatformRemediationEvents();
        }

        $resolutionTimes = [];
        $resolvedCount = 0;
        $unresolvedCount = 0;

        foreach ($events as $ev) {
            $details = $ev['details'] ?? [];
            
            if (!empty($ev['created_at']) && !empty($details['resolved_at'])) {
                $start = strtotime($ev['created_at']);
                $end = strtotime($details['resolved_at']);
                
                if ($start !== false && $end !== false && $end > $start) {
                    $hours = ($end - $start) / 3600.0;
                    $resolutionTimes[] = $hours;
                    
                    if ((isset($details['success']) && $details['success']) || in_array($details['outcome'] ?? '', ['success', 'ok', 'resolved'])) {
                        $resolvedCount++;
                    }
                }
            } elseif (!empty($ev['created_at']) && empty($details['resolved_at'])) {
                $unresolvedCount++;
            }
        }

        $avgMTTR = count($resolutionTimes) > 0 ? round(array_sum($resolutionTimes) / count($resolutionTimes), 2) : 0;
        $p95MTTR = count($resolutionTimes) > 0 ? round(self::percentile($resolutionTimes, 95), 2) : 0;

        return [
            'mttr_hours_avg' => $avgMTTR,
            'mttr_hours_p95' => $p95MTTR,
            'resolved_count_7d' => self::countResolved($events, 7),
            'unresolved_count' => $unresolvedCount,
        ];
    }

    /**
     * Compute recommendation acceptance rate
     */
    public static function computeAcceptanceRate($events = null)
    {
        if (!$events) {
            $events = getPlatformRemediationEvents();
        }

        $generated = count($events);
        $accepted = 0;

        foreach ($events as $ev) {
            if (isset($ev['accepted']) && $ev['accepted']) {
                $accepted++;
            }
        }

        $overallRate = $generated > 0 ? round($accepted / $generated, 4) : 0;

        // Per-type acceptance
        $byType = [];
        foreach ($events as $ev) {
            $type = $ev['recommendation_type'] ?? 'unknown';
            if (!isset($byType[$type])) {
                $byType[$type] = ['generated' => 0, 'accepted' => 0];
            }
            $byType[$type]['generated']++;
            if (isset($ev['accepted']) && $ev['accepted']) {
                $byType[$type]['accepted']++;
            }
        }

        $byTypeRates = [];
        foreach ($byType as $type => $counts) {
            $byTypeRates[$type] = $counts['generated'] > 0 ? round($counts['accepted'] / $counts['generated'], 4) : 0;
        }

        return [
            'overall_acceptance_rate' => $overallRate,
            'by_type' => $byTypeRates,
            'trend_7d' => self::computeAcceptanceRateTrend($events, 7),
            'trend_30d' => self::computeAcceptanceRateTrend($events, 30),
        ];
    }

    /**
     * Compute intelligence accuracy metrics
     */
    public static function computeAccuracy($events = null)
    {
        if (!$events) {
            $events = getPlatformRemediationEvents();
        }

        $detected = self::countEventsInPeriod($events, 7);
        $confirmed = 0;
        $falsePositives = 0;

        foreach ($events as $ev) {
            $details = $ev['details'] ?? [];
            if ((isset($details['success']) && $details['success']) || in_array($details['outcome'] ?? '', ['success', 'ok', 'confirmed'])) {
                $confirmed++;
            } elseif ($details['outcome'] === 'false_positive') {
                $falsePositives++;
            }
        }

        $precision = $detected > 0 ? round($confirmed / $detected, 4) : 0;
        $falsePositiveRate = $detected > 0 ? round($falsePositives / $detected, 4) : 0;

        return [
            'detected_anomalies_7d' => $detected,
            'confirmed_true_anomalies' => $confirmed,
            'false_positives' => $falsePositives,
            'precision' => $precision,
            'false_positive_rate' => $falsePositiveRate,
            'recent_accuracy_trend' => $precision > 0.85 ? 'improving' : 'needs_attention',
        ];
    }

    // Helper methods
    private static function percentile($array, $percentile)
    {
        sort($array);
        $index = (count($array) - 1) * ($percentile / 100.0);
        $lower = floor($index);
        $upper = ceil($index);
        $weight = $index - $lower;
        if ($lower === $upper) return $array[$lower];
        return $array[$lower] * (1 - $weight) + $array[$upper] * $weight;
    }

    private static function countEventsInPeriod($events, $days)
    {
        $cutoff = strtotime("-$days days");
        $count = 0;
        foreach ($events as $ev) {
            if (!empty($ev['created_at'])) {
                $time = strtotime($ev['created_at']);
                if ($time !== false && $time >= $cutoff) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private static function countResolved($events, $days)
    {
        $cutoff = strtotime("-$days days");
        $count = 0;
        foreach ($events as $ev) {
            $details = $ev['details'] ?? [];
            if (!empty($details['resolved_at'])) {
                $time = strtotime($details['resolved_at']);
                if ($time !== false && $time >= $cutoff && (isset($details['success']) && $details['success'])) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private static function computeAcceptanceRateTrend($events, $days)
    {
        $cutoff = strtotime("-$days days");
        $generated = 0;
        $accepted = 0;
        foreach ($events as $ev) {
            if (!empty($ev['created_at'])) {
                $time = strtotime($ev['created_at']);
                if ($time !== false && $time >= $cutoff) {
                    $generated++;
                    if (isset($ev['accepted']) && $ev['accepted']) {
                        $accepted++;
                    }
                }
            }
        }
        return $generated > 0 ? round($accepted / $generated, 4) : 0;
    }
}
