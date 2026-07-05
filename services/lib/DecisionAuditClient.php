<?php

/**
 * Decision Audit Integration
 * Helper for services to record decisions to the audit layer
 */

class DecisionAuditClient
{
    private static $audit_service_url = 'http://127.0.0.1:8004';
    private static $timeout = 10;

    public static function setAuditServiceUrl(string $url): void
    {
        self::$audit_service_url = $url;
    }

    /**
     * Record a recommendation decision
     */
    public static function recordRecommendation(
        string $tenant_id,
        array $triggering_metrics,
        array $evidence,
        string $model_version,
        string $recommendation,
        ?array $recommendation_detail,
        float $confidence,
        string $priority = 'medium'
    ): ?string {
        return self::recordDecision(
            tenant_id: $tenant_id,
            decision_type: 'recommendation',
            source_service: 'intelligence',
            triggering_metrics: $triggering_metrics,
            evidence: $evidence,
            model_version: $model_version,
            recommendation: $recommendation,
            recommendation_detail: $recommendation_detail,
            confidence: $confidence,
            priority: $priority
        );
    }

    /**
     * Record a remediation decision
     */
    public static function recordRemediation(
        string $tenant_id,
        array $triggering_metrics,
        array $evidence,
        string $model_version,
        string $recommendation,
        ?array $recommendation_detail,
        float $confidence,
        string $priority = 'high'
    ): ?string {
        return self::recordDecision(
            tenant_id: $tenant_id,
            decision_type: 'remediation',
            source_service: 'operational_readiness',
            triggering_metrics: $triggering_metrics,
            evidence: $evidence,
            model_version: $model_version,
            recommendation: $recommendation,
            recommendation_detail: $recommendation_detail,
            confidence: $confidence,
            priority: $priority
        );
    }

    /**
     * Record a learning update decision
     */
    public static function recordLearningUpdate(
        array $triggering_metrics,
        array $evidence,
        string $model_version,
        string $recommendation,
        float $confidence
    ): ?string {
        return self::recordDecision(
            tenant_id: 'platform',
            decision_type: 'learning_update',
            source_service: 'learning',
            triggering_metrics: $triggering_metrics,
            evidence: $evidence,
            model_version: $model_version,
            recommendation: $recommendation,
            recommendation_detail: null,
            confidence: $confidence,
            priority: 'medium'
        );
    }

    /**
     * Record a generic decision
     */
    private static function recordDecision(
        string $tenant_id,
        string $decision_type,
        string $source_service,
        array $triggering_metrics,
        array $evidence,
        string $model_version,
        string $recommendation,
        ?array $recommendation_detail,
        float $confidence,
        string $priority
    ): ?string {
        try {
            $payload = [
                'tenant_id' => $tenant_id,
                'decision_type' => $decision_type,
                'source_service' => $source_service,
                'triggering_metrics' => $triggering_metrics,
                'evidence' => $evidence,
                'model_version' => $model_version,
                'recommendation' => $recommendation,
                'recommendation_detail' => $recommendation_detail,
                'confidence' => $confidence,
                'priority' => $priority,
            ];

            $ch = curl_init(self::$audit_service_url . '/decisions');
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::$timeout,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Request-Id: ' . ServiceHelpers::getOrCreateRequestId(),
                    'X-Trace-Id: ' . (ServiceHelpers::getTraceContext()['trace_id'] ?? uniqid()),
                ],
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code === 201 && $response) {
                $data = json_decode($response, true);
                return $data['decision_id'] ?? null;
            }

            return null;
        } catch (Throwable $e) {
            error_log("Failed to record decision: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Record operator action (accept/reject/etc)
     */
    public static function recordOperatorAction(
        string $decision_id,
        string $action,
        ?string $notes = null,
        ?string $operator_id = null
    ): bool {
        try {
            $payload = [
                'operator_action' => $action,
                'operator_notes' => $notes,
                'operator_id' => $operator_id,
            ];

            $ch = curl_init(self::$audit_service_url . '/decisions/' . $decision_id . '/action');
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::$timeout,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Request-Id: ' . ServiceHelpers::getOrCreateRequestId(),
                ],
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $http_code === 200;
        } catch (Throwable $e) {
            error_log("Failed to record operator action: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record execution start
     */
    public static function recordExecutionStart(string $decision_id): bool
    {
        try {
            $ch = curl_init(self::$audit_service_url . '/decisions/' . $decision_id . '/execution-status');
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_POSTFIELDS => json_encode(['status' => 'executing']),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::$timeout,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Request-Id: ' . ServiceHelpers::getOrCreateRequestId(),
                ],
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $http_code === 200;
        } catch (Throwable $e) {
            error_log("Failed to record execution start: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record execution completion
     */
    public static function recordExecutionEnd(
        string $decision_id,
        string $status,
        ?string $error = null
    ): bool {
        try {
            $payload = [
                'status' => $status,
                'error' => $error,
            ];

            $ch = curl_init(self::$audit_service_url . '/decisions/' . $decision_id . '/execution-status');
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::$timeout,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Request-Id: ' . ServiceHelpers::getOrCreateRequestId(),
                ],
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $http_code === 200;
        } catch (Throwable $e) {
            error_log("Failed to record execution end: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record effectiveness and feedback
     */
    public static function recordEffectiveness(
        string $decision_id,
        array $health_before,
        array $health_after,
        ?array $learning_feedback = null
    ): bool {
        try {
            $payload = [
                'health_before' => $health_before,
                'health_after' => $health_after,
                'learning_feedback' => $learning_feedback,
            ];

            $ch = curl_init(self::$audit_service_url . '/decisions/' . $decision_id . '/effectiveness');
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::$timeout,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Request-Id: ' . ServiceHelpers::getOrCreateRequestId(),
                ],
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $http_code === 200;
        } catch (Throwable $e) {
            error_log("Failed to record effectiveness: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get decision details
     */
    public static function getDecision(string $decision_id): ?array
    {
        try {
            $ch = curl_init(self::$audit_service_url . '/decisions/' . $decision_id);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::$timeout,
                CURLOPT_HTTPHEADER => [
                    'X-Request-Id: ' . ServiceHelpers::getOrCreateRequestId(),
                ],
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code === 200 && $response) {
                return json_decode($response, true);
            }

            return null;
        } catch (Throwable $e) {
            error_log("Failed to get decision: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get decision timeline for tenant
     */
    public static function getDecisionTimeline(
        string $tenant_id,
        ?string $start_date = null,
        ?string $end_date = null,
        int $limit = 50
    ): array {
        try {
            $start_date = $start_date ?? date('c', time() - 86400 * 7);
            $end_date = $end_date ?? date('c');

            $url = self::$audit_service_url . '/timeline?tenant_id=' . urlencode($tenant_id)
                . '&start_date=' . urlencode($start_date)
                . '&end_date=' . urlencode($end_date)
                . '&limit=' . $limit;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::$timeout,
                CURLOPT_HTTPHEADER => [
                    'X-Request-Id: ' . ServiceHelpers::getOrCreateRequestId(),
                ],
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code === 200 && $response) {
                $data = json_decode($response, true);
                return $data['decisions'] ?? [];
            }

            return [];
        } catch (Throwable $e) {
            error_log("Failed to get decision timeline: " . $e->getMessage());
            return [];
        }
    }
}
