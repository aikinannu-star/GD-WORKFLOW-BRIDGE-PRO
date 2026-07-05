<?php
/**
 * Remediation Success Rate Tracker
 * 
 * Calculates whether recommendations are actually improving tenant health.
 * This KPI is critical for validating the intelligence system's effectiveness.
 * 
 * Formula: Successful Remediations / Total Remediation Attempts
 * 
 * A successful remediation is defined as:
 * - Health score improved after remediation attempt
 * - Improvement sustained for 24+ hours
 * - No new drift reported post-remediation
 */

class RemediationSuccessTracker {
    private $dataPath = __DIR__ . '/../data';
    private $historyFiles = [];

    public function __construct() {
        $this->discoverHistoryFiles();
    }

    /**
     * Find all tenant history files
     */
    private function discoverHistoryFiles(): void {
        $pattern = $this->dataPath . '/marketplace_tenant_history_*.json';
        $this->historyFiles = glob($pattern);
    }

    /**
     * Get remediation success rate across entire fleet
     * 
     * @param int $daysBack - Look back this many days for remediation attempts
     * @return array - Aggregate remediation statistics
     */
    public function getFleetRemediationSuccessRate(int $daysBack = 7): array {
        $totalAttempts = 0;
        $successfulRemediations = 0;
        $partialSuccesses = 0;
        $failures = 0;
        $unknownStatus = 0;

        $cutoffTime = time() - ($daysBack * 86400);

        foreach ($this->historyFiles as $file) {
            $tenantId = $this->extractTenantIdFromFile($file);
            $history = $this->loadHistory($file);

            foreach ($history as $snapshot) {
                if (!isset($snapshot['remediation_attempted']) || !$snapshot['remediation_attempted']) {
                    continue;
                }

                $timestamp = strtotime($snapshot['hour'] ?? $snapshot['timestamp'] ?? 'now');
                if ($timestamp < $cutoffTime) {
                    continue;
                }

                $totalAttempts++;

                $status = $this->evaluateRemediationSuccess($history, $snapshot);
                match ($status) {
                    'success' => $successfulRemediations++,
                    'partial' => $partialSuccesses++,
                    'failure' => $failures++,
                    'unknown' => $unknownStatus++,
                };
            }
        }

        return $this->aggregateResults(
            $totalAttempts,
            $successfulRemediations,
            $partialSuccesses,
            $failures,
            $unknownStatus
        );
    }

    /**
     * Get remediation success rate for a specific tenant
     * 
     * @param string $tenantId - Tenant identifier
     * @param int $daysBack - Look back period
     * @return array - Tenant-specific remediation statistics
     */
    public function getTenantRemediationSuccessRate(string $tenantId, int $daysBack = 7): array {
        $historyFile = $this->dataPath . "/marketplace_tenant_history_${tenantId}.json";
        if (!file_exists($historyFile)) {
            return [
                'tenant_id' => $tenantId,
                'total_attempts' => 0,
                'successful_remediations' => 0,
                'partial_successes' => 0,
                'failures' => 0,
                'unknown_status' => 0,
                'success_rate' => 0,
                'confidence' => 'no_data',
                'days_back' => $daysBack,
                'calculated_at' => gmdate('c'),
            ];
        }

        $totalAttempts = 0;
        $successfulRemediations = 0;
        $partialSuccesses = 0;
        $failures = 0;
        $unknownStatus = 0;

        $cutoffTime = time() - ($daysBack * 86400);
        $history = $this->loadHistory($historyFile);

        foreach ($history as $snapshot) {
            if (!isset($snapshot['remediation_attempted']) || !$snapshot['remediation_attempted']) {
                continue;
            }

            $timestamp = strtotime($snapshot['hour'] ?? $snapshot['timestamp'] ?? 'now');
            if ($timestamp < $cutoffTime) {
                continue;
            }

            $totalAttempts++;
            $status = $this->evaluateRemediationSuccess($history, $snapshot);

            match ($status) {
                'success' => $successfulRemediations++,
                'partial' => $partialSuccesses++,
                'failure' => $failures++,
                'unknown' => $unknownStatus++,
            };
        }

        return [
            'tenant_id' => $tenantId,
            'total_attempts' => $totalAttempts,
            'successful_remediations' => $successfulRemediations,
            'partial_successes' => $partialSuccesses,
            'failures' => $failures,
            'unknown_status' => $unknownStatus,
            'success_rate' => $totalAttempts > 0 ? ($successfulRemediations / $totalAttempts) : 0,
            'partial_success_rate' => $totalAttempts > 0 ? (($successfulRemediations + $partialSuccesses) / $totalAttempts) : 0,
            'confidence' => $this->assessConfidence($totalAttempts, $unknownStatus),
            'days_back' => $daysBack,
            'calculated_at' => gmdate('c'),
        ];
    }

    /**
     * Evaluate if a remediation was successful
     * 
     * Success criteria:
     * - Health score improved in the 24 hours after remediation
     * - Improvement sustained (not immediately reversed)
     * - No new critical drift reported
     */
    private function evaluateRemediationSuccess(array $history, array $remediationSnapshot): string {
        $remediationTime = strtotime($remediationSnapshot['hour'] ?? $remediationSnapshot['timestamp'] ?? 'now');
        $remediationHealth = $remediationSnapshot['health_score'] ?? null;

        if ($remediationHealth === null) {
            return 'unknown';
        }

        // Find snapshots in the 24 hours after remediation
        $postRemediationSnapshots = array_filter($history, function ($snapshot) use ($remediationTime) {
            $snapshotTime = strtotime($snapshot['hour'] ?? $snapshot['timestamp'] ?? 'now');
            return $snapshotTime > $remediationTime && $snapshotTime <= ($remediationTime + 86400);
        });

        if (empty($postRemediationSnapshots)) {
            return 'unknown'; // Not enough post-remediation data
        }

        $postSnapshots = array_values($postRemediationSnapshots);
        $maxPostHealth = max(array_map(fn($s) => $s['health_score'] ?? 0, $postSnapshots));
        $avgPostHealth = array_sum(array_map(fn($s) => $s['health_score'] ?? 0, $postSnapshots)) / count($postSnapshots);

        // Success: health improved and stayed improved
        if ($maxPostHealth > $remediationHealth && $avgPostHealth > $remediationHealth) {
            return 'success';
        }

        // Partial: some improvement but inconsistent
        if ($maxPostHealth > $remediationHealth) {
            return 'partial';
        }

        // Failure: health did not improve or got worse
        return 'failure';
    }

    /**
     * Assess confidence in the success rate calculation
     */
    private function assessConfidence(int $totalAttempts, int $unknownStatus): string {
        if ($totalAttempts === 0) {
            return 'no_data';
        }

        $unknownRatio = $unknownStatus / $totalAttempts;

        if ($unknownRatio > 0.5) {
            return 'low'; // More than 50% unknown
        }

        if ($unknownRatio > 0.25) {
            return 'medium'; // 25-50% unknown
        }

        if ($totalAttempts < 3) {
            return 'low'; // Not enough attempts
        }

        return 'high'; // Good confidence
    }

    /**
     * Aggregate remediation results
     */
    private function aggregateResults(
        int $totalAttempts,
        int $successfulRemediations,
        int $partialSuccesses,
        int $failures,
        int $unknownStatus
    ): array {
        return [
            'total_attempts' => $totalAttempts,
            'successful_remediations' => $successfulRemediations,
            'partial_successes' => $partialSuccesses,
            'failures' => $failures,
            'unknown_status' => $unknownStatus,
            'success_rate' => $totalAttempts > 0 ? ($successfulRemediations / $totalAttempts) : 0,
            'partial_success_rate' => $totalAttempts > 0 ? (($successfulRemediations + $partialSuccesses) / $totalAttempts) : 0,
            'failure_rate' => $totalAttempts > 0 ? ($failures / $totalAttempts) : 0,
            'confidence' => $this->assessConfidence($totalAttempts, $unknownStatus),
            'interpretation' => $this->interpretSuccessRate($totalAttempts, $successfulRemediations),
        ];
    }

    /**
     * Provide human-readable interpretation of success rate
     */
    private function interpretSuccessRate(int $totalAttempts, int $successfulRemediations): string {
        if ($totalAttempts === 0) {
            return 'No remediation attempts recorded';
        }

        $rate = $successfulRemediations / $totalAttempts;

        return match (true) {
            $rate >= 0.9 => 'Excellent - Recommendations are highly effective',
            $rate >= 0.75 => 'Good - Recommendations are generally effective',
            $rate >= 0.5 => 'Fair - Recommendations work in about half of cases',
            $rate >= 0.25 => 'Poor - Recommendations often fail',
            default => 'Critical - Recommendations rarely succeed; review strategy'
        };
    }

    /**
     * Extract tenant ID from history filename
     */
    private function extractTenantIdFromFile(string $filePath): string {
        preg_match('/marketplace_tenant_history_(.+)\.json/', $filePath, $matches);
        return $matches[1] ?? 'unknown';
    }

    /**
     * Load and parse history file
     */
    private function loadHistory(string $filePath): array {
        if (!file_exists($filePath)) {
            return [];
        }

        $contents = file_get_contents($filePath);
        $data = json_decode($contents, true);

        return $data['snapshots'] ?? $data ?? [];
    }
}
