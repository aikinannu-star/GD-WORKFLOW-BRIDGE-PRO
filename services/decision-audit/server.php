<?php

// Decision Audit Service - Sprint 7.4
// Comprehensive audit trail for all platform recommendations and decisions

require_once __DIR__ . '/../../services/lib/ServiceHelpers.php';
require_once __DIR__ . '/DecisionAuditDB.php';

$service_name = 'decision-audit';
$port = 8004;

// Initialize database
try {
    DecisionAuditDB::createDatabaseIfNotExists();
    DecisionAuditDB::initializeSchema();
} catch (Exception $e) {
    ServiceHelpers::emitStructuredLog($service_name, 'error', 'Database initialization failed', [
        'error' => $e->getMessage(),
    ]);
    ServiceHelpers::sendJson(500, [
        'error' => 'Database initialization failed',
        'message' => $e->getMessage(),
    ]);
}

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/decision-audit#', '', $path) ?: '/';
$query = $_GET;
$body = ServiceHelpers::getRequestBody();

// Trace context
$trace = ServiceHelpers::getTraceContext();
$request_id = ServiceHelpers::getOrCreateRequestId();

try {
    switch ($path) {
        // Health endpoints
        case '/health':
        case '/healthz':
            try {
                $db = DecisionAuditDB::connect();
                $db->query('SELECT 1');
                ServiceHelpers::sendJson(200, [
                    'status' => 'healthy',
                    'service' => $service_name,
                    'timestamp' => gmdate('c'),
                ]);
            } catch (Exception $e) {
                ServiceHelpers::sendJson(503, [
                    'status' => 'unhealthy',
                    'reason' => 'database_unavailable',
                ]);
            }
            break;

        // Readiness endpoint
        case '/readyz':
            try {
                $db = DecisionAuditDB::connect();
                $db->query('SELECT 1');
                ServiceHelpers::sendJson(200, [
                    'status' => 'ready',
                    'service' => $service_name,
                ]);
            } catch (Exception $e) {
                ServiceHelpers::sendJson(503, [
                    'status' => 'not_ready',
                    'reason' => 'database_unavailable',
                ]);
            }
            break;

        // Metrics endpoint
        case '/metrics':
            $metrics = ServiceHelpers::renderPrometheusMetrics($service_name);
            ServiceHelpers::sendText(200, $metrics, 'text/plain; version=0.0.4');
            break;

        // Record decision
        case '/decisions':
            if ($method === 'POST') {
                // Validate required fields
                $required = ['tenant_id', 'decision_type', 'source_service', 'model_version', 'recommendation', 'confidence', 'priority'];
                foreach ($required as $field) {
                    if (empty($body[$field])) {
                        ServiceHelpers::emitStructuredLog($service_name, 'warn', 'Missing required field', ['field' => $field]);
                        ServiceHelpers::sendJson(400, ['error' => "Missing required field: $field"]);
                    }
                }

                try {
                    $decision_id = DecisionAuditDB::recordDecision([
                        'tenant_id' => $body['tenant_id'],
                        'decision_type' => $body['decision_type'],
                        'source_service' => $body['source_service'],
                        'triggering_metrics' => $body['triggering_metrics'] ?? [],
                        'evidence' => $body['evidence'] ?? [],
                        'model_version' => $body['model_version'],
                        'recommendation' => $body['recommendation'],
                        'recommendation_detail' => $body['recommendation_detail'] ?? null,
                        'confidence' => (float)$body['confidence'],
                        'priority' => $body['priority'],
                    ]);

                    ServiceHelpers::incrementMetric($service_name, 'decisions_recorded_total', [
                        'decision_type' => $body['decision_type'],
                        'source_service' => $body['source_service'],
                        'priority' => $body['priority'],
                    ]);

                    ServiceHelpers::emitStructuredLog($service_name, 'info', 'Decision recorded', [
                        'decision_id' => $decision_id,
                        'tenant_id' => $body['tenant_id'],
                        'decision_type' => $body['decision_type'],
                    ]);

                    ServiceHelpers::sendJson(201, [
                        'decision_id' => $decision_id,
                        'timestamp' => gmdate('c'),
                        'status' => 'recorded',
                    ]);
                } catch (Exception $e) {
                    ServiceHelpers::incrementMetric($service_name, 'decisions_errors_total', [
                        'operation' => 'record',
                        'error_type' => 'database_error',
                    ]);
                    ServiceHelpers::emitStructuredLog($service_name, 'error', 'Failed to record decision', [
                        'error' => $e->getMessage(),
                    ]);
                    ServiceHelpers::sendJson(500, ['error' => 'Failed to record decision']);
                }
            }
            // Get decisions (GET /decisions?filters)
            elseif ($method === 'GET') {
                try {
                    $filters = [];
                    foreach (['tenant_id', 'decision_type', 'source_service', 'operator_action', 'priority'] as $key) {
                        if (isset($query[$key])) {
                            $filters[$key] = $query[$key];
                        }
                    }

                    if (isset($query['start_date'])) {
                        $filters['start_date'] = $query['start_date'];
                    }
                    if (isset($query['end_date'])) {
                        $filters['end_date'] = $query['end_date'];
                    }
                    if (isset($query['min_effectiveness'])) {
                        $filters['min_effectiveness'] = (float)$query['min_effectiveness'];
                    }

                    $limit = min((int)($query['limit'] ?? 50), 500);
                    $offset = (int)($query['offset'] ?? 0);

                    $decisions = DecisionAuditDB::getDecisions($filters, $limit, $offset);

                    ServiceHelpers::incrementMetric($service_name, 'decisions_queried_total', [
                        'decision_type' => $filters['decision_type'] ?? 'all',
                    ]);

                    ServiceHelpers::sendJson(200, [
                        'decisions' => $decisions,
                        'count' => count($decisions),
                        'limit' => $limit,
                        'offset' => $offset,
                    ]);
                } catch (Exception $e) {
                    ServiceHelpers::emitStructuredLog($service_name, 'error', 'Failed to query decisions', [
                        'error' => $e->getMessage(),
                    ]);
                    ServiceHelpers::sendJson(500, ['error' => 'Failed to query decisions']);
                }
            } else {
                ServiceHelpers::sendJson(405, ['error' => 'Method not allowed']);
            }
            break;

        // Get specific decision
        case preg_match('#^/decisions/([a-f0-9]+)$#', $path, $matches) ? '/decisions/{id}' : null:
            $decision_id = $matches[1];

            if ($method === 'GET') {
                try {
                    $decision = DecisionAuditDB::getDecision($decision_id);

                    if (!$decision) {
                        ServiceHelpers::sendJson(404, ['error' => 'Decision not found']);
                    }

                    ServiceHelpers::sendJson(200, $decision);
                } catch (Exception $e) {
                    ServiceHelpers::emitStructuredLog($service_name, 'error', 'Failed to get decision', [
                        'decision_id' => $decision_id,
                        'error' => $e->getMessage(),
                    ]);
                    ServiceHelpers::sendJson(500, ['error' => 'Failed to get decision']);
                }
            } else {
                ServiceHelpers::sendJson(405, ['error' => 'Method not allowed']);
            }
            break;

        // Decision timeline
        case preg_match('#^/decisions/([a-f0-9]+)/timeline$#', $path, $matches) ? '/decisions/{id}/timeline' : null:
            $decision_id = $matches[1];

            if ($method === 'GET') {
                try {
                    $decision = DecisionAuditDB::getDecision($decision_id);
                    if (!$decision) {
                        ServiceHelpers::sendJson(404, ['error' => 'Decision not found']);
                    }

                    $related = DecisionAuditDB::getRelatedDecisions($decision_id);

                    ServiceHelpers::sendJson(200, [
                        'decision' => $decision,
                        'related_decisions' => $related,
                        'timeline_count' => count($related),
                    ]);
                } catch (Exception $e) {
                    ServiceHelpers::emitStructuredLog($service_name, 'error', 'Failed to get decision timeline', [
                        'decision_id' => $decision_id,
                        'error' => $e->getMessage(),
                    ]);
                    ServiceHelpers::sendJson(500, ['error' => 'Failed to get decision timeline']);
                }
            } else {
                ServiceHelpers::sendJson(405, ['error' => 'Method not allowed']);
            }
            break;

        // Record operator action
        case preg_match('#^/decisions/([a-f0-9]+)/action$#', $path, $matches) ? '/decisions/{id}/action' : null:
            $decision_id = $matches[1];

            if ($method === 'PATCH') {
                $valid_actions = ['accepted', 'rejected', 'deferred', 'overridden'];
                if (empty($body['operator_action']) || !in_array($body['operator_action'], $valid_actions)) {
                    ServiceHelpers::sendJson(400, ['error' => 'Invalid operator_action']);
                }

                try {
                    DecisionAuditDB::recordOperatorAction(
                        $decision_id,
                        $body['operator_action'],
                        $body['operator_notes'] ?? null,
                        $body['operator_id'] ?? null
                    );

                    ServiceHelpers::incrementMetric($service_name, 'decisions_operator_action_total', [
                        'action' => $body['operator_action'],
                    ]);

                    ServiceHelpers::emitStructuredLog($service_name, 'info', 'Operator action recorded', [
                        'decision_id' => $decision_id,
                        'action' => $body['operator_action'],
                    ]);

                    ServiceHelpers::sendJson(200, [
                        'decision_id' => $decision_id,
                        'operator_action' => $body['operator_action'],
                        'updated_at' => gmdate('c'),
                    ]);
                } catch (Exception $e) {
                    ServiceHelpers::emitStructuredLog($service_name, 'error', 'Failed to record operator action', [
                        'decision_id' => $decision_id,
                        'error' => $e->getMessage(),
                    ]);
                    ServiceHelpers::sendJson(500, ['error' => 'Failed to record operator action']);
                }
            } else {
                ServiceHelpers::sendJson(405, ['error' => 'Method not allowed']);
            }
            break;

        // Execution status
        case preg_match('#^/decisions/([a-f0-9]+)/execution-status$#', $path, $matches) ? '/decisions/{id}/execution-status' : null:
            $decision_id = $matches[1];

            if ($method === 'PATCH') {
                $valid_statuses = ['executing', 'completed', 'failed'];
                if (empty($body['status']) || !in_array($body['status'], $valid_statuses)) {
                    ServiceHelpers::sendJson(400, ['error' => 'Invalid status']);
                }

                try {
                    DecisionAuditDB::recordExecutionEnd(
                        $decision_id,
                        $body['status'],
                        $body['error'] ?? null
                    );

                    ServiceHelpers::incrementMetric($service_name, 'decisions_execution_total', [
                        'status' => $body['status'],
                    ]);

                    ServiceHelpers::emitStructuredLog($service_name, 'info', 'Execution status updated', [
                        'decision_id' => $decision_id,
                        'status' => $body['status'],
                    ]);

                    ServiceHelpers::sendJson(200, [
                        'decision_id' => $decision_id,
                        'status' => $body['status'],
                        'updated_at' => gmdate('c'),
                    ]);
                } catch (Exception $e) {
                    ServiceHelpers::emitStructuredLog($service_name, 'error', 'Failed to update execution status', [
                        'decision_id' => $decision_id,
                        'error' => $e->getMessage(),
                    ]);
                    ServiceHelpers::sendJson(500, ['error' => 'Failed to update execution status']);
                }
            } else {
                ServiceHelpers::sendJson(405, ['error' => 'Method not allowed']);
            }
            break;

        // Effectiveness feedback
        case preg_match('#^/decisions/([a-f0-9]+)/effectiveness$#', $path, $matches) ? '/decisions/{id}/effectiveness' : null:
            $decision_id = $matches[1];

            if ($method === 'POST') {
                try {
                    DecisionAuditDB::calculateEffectiveness(
                        $decision_id,
                        $body['health_before'] ?? [],
                        $body['health_after'] ?? []
                    );

                    DecisionAuditDB::recordLearningFeedback($decision_id, $body['learning_feedback'] ?? []);

                    ServiceHelpers::emitStructuredLog($service_name, 'info', 'Effectiveness calculated', [
                        'decision_id' => $decision_id,
                    ]);

                    ServiceHelpers::sendJson(200, [
                        'decision_id' => $decision_id,
                        'effectiveness_calculated' => true,
                    ]);
                } catch (Exception $e) {
                    ServiceHelpers::emitStructuredLog($service_name, 'error', 'Failed to calculate effectiveness', [
                        'decision_id' => $decision_id,
                        'error' => $e->getMessage(),
                    ]);
                    ServiceHelpers::sendJson(500, ['error' => 'Failed to calculate effectiveness']);
                }
            } else {
                ServiceHelpers::sendJson(405, ['error' => 'Method not allowed']);
            }
            break;

        // Decision timeline for tenant
        case '/timeline':
            if ($method === 'GET') {
                try {
                    $tenant_id = $query['tenant_id'] ?? null;
                    $start_date = $query['start_date'] ?? date('c', time() - 86400 * 7);
                    $end_date = $query['end_date'] ?? date('c');
                    $limit = min((int)($query['limit'] ?? 50), 500);

                    if (!$tenant_id) {
                        ServiceHelpers::sendJson(400, ['error' => 'tenant_id is required']);
                    }

                    $timeline = DecisionAuditDB::getDecisionTimeline($tenant_id, $start_date, $end_date, $limit);

                    ServiceHelpers::sendJson(200, [
                        'tenant_id' => $tenant_id,
                        'decisions' => $timeline,
                        'count' => count($timeline),
                    ]);
                } catch (Exception $e) {
                    ServiceHelpers::emitStructuredLog($service_name, 'error', 'Failed to get timeline', [
                        'error' => $e->getMessage(),
                    ]);
                    ServiceHelpers::sendJson(500, ['error' => 'Failed to get timeline']);
                }
            } else {
                ServiceHelpers::sendJson(405, ['error' => 'Method not allowed']);
            }
            break;

        // Analytics
        case '/analytics':
            if ($method === 'GET') {
                try {
                    $filters = [];
                    if (isset($query['tenant_id'])) {
                        $filters['tenant_id'] = $query['tenant_id'];
                    }
                    if (isset($query['start_date'])) {
                        $filters['start_date'] = $query['start_date'];
                    }
                    if (isset($query['end_date'])) {
                        $filters['end_date'] = $query['end_date'];
                    }

                    $analytics = DecisionAuditDB::getAnalytics($filters);

                    ServiceHelpers::sendJson(200, [
                        'analytics' => $analytics,
                        'count' => count($analytics),
                    ]);
                } catch (Exception $e) {
                    ServiceHelpers::emitStructuredLog($service_name, 'error', 'Failed to get analytics', [
                        'error' => $e->getMessage(),
                    ]);
                    ServiceHelpers::sendJson(500, ['error' => 'Failed to get analytics']);
                }
            } else {
                ServiceHelpers::sendJson(405, ['error' => 'Method not allowed']);
            }
            break;

        // Export decision
        case preg_match('#^/decisions/([a-f0-9]+)/export$#', $path, $matches) ? '/decisions/{id}/export' : null:
            $decision_id = $matches[1];

            if ($method === 'GET') {
                try {
                    $format = $query['format'] ?? 'json';
                    if (!in_array($format, ['json', 'csv', 'pdf'])) {
                        ServiceHelpers::sendJson(400, ['error' => 'Invalid format']);
                    }

                    $export = DecisionAuditDB::exportDecisionRecord(
                        $decision_id,
                        $format,
                        $query['purpose'] ?? null,
                        $query['exported_by'] ?? null
                    );

                    $contentType = match ($format) {
                        'json' => 'application/json',
                        'csv' => 'text/csv',
                        'pdf' => 'application/pdf',
                        default => 'text/plain',
                    };

                    ServiceHelpers::sendText(200, $export, $contentType);
                } catch (Exception $e) {
                    ServiceHelpers::emitStructuredLog($service_name, 'error', 'Failed to export decision', [
                        'decision_id' => $decision_id,
                        'error' => $e->getMessage(),
                    ]);
                    ServiceHelpers::sendJson(500, ['error' => 'Failed to export decision']);
                }
            } else {
                ServiceHelpers::sendJson(405, ['error' => 'Method not allowed']);
            }
            break;

        default:
            ServiceHelpers::emitStructuredLog($service_name, 'warn', 'Route not found', ['path' => $path]);
            ServiceHelpers::sendJson(404, ['error' => 'Route not found']);
    }
} catch (Exception $e) {
    ServiceHelpers::incrementMetric($service_name, 'errors_total', ['error_type' => 'unhandled']);
    ServiceHelpers::emitStructuredLog($service_name, 'error', 'Unhandled exception', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    ServiceHelpers::sendJson(500, ['error' => 'Internal server error']);
}
