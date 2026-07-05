# Sprint 7.4 Decision Audit Layer - Implementation Guide

## What Was Built

Sprint 7.4 establishes the **Decision Audit Layer**, transforming the platform from a black-box intelligence engine into an auditable, explainable system with complete decision traceability.

### Components Delivered

#### 1. Decision Audit Service (Port 8004)
- **File**: [services/decision-audit/server.php](../services/decision-audit/server.php)
- **Purpose**: Central audit hub for recording and querying all platform decisions
- **Status**: ✅ Fully implemented with REST API endpoints

#### 2. Database Layer
- **Schema**: [services/decision-audit/schema.sql](../services/decision-audit/schema.sql)
- **ORM**: [services/decision-audit/DecisionAuditDB.php](../services/decision-audit/DecisionAuditDB.php)
- **Purpose**: MySQL-based storage for decision records, relationships, and analytics
- **Tables**:
  - `decisions` — Full audit trail of all decisions
  - `decision_relationships` — Causality tracking between decisions
  - `decision_exports` — Compliance export audit trail
  - `decision_analytics` — Pre-calculated metrics

#### 3. Client Integration Library
- **File**: [services/lib/DecisionAuditClient.php](../services/lib/DecisionAuditClient.php)
- **Purpose**: Simple interface for services to record decisions to the audit layer
- **Methods**:
  - `recordRecommendation()` — Record intelligence recommendations
  - `recordRemediation()` — Record operational remediation actions
  - `recordLearningUpdate()` — Record learning engine model updates
  - `recordOperatorAction()` — Track operator acceptance/rejection
  - `recordExecutionEnd()` — Track execution outcomes
  - `recordEffectiveness()` — Calculate effectiveness 24 hours post-execution

#### 4. Operations Center UI
- **File**: [services/marketplace/public/operations-center/decision-timeline.html](../services/marketplace/public/operations-center/decision-timeline.html)
- **Purpose**: Visual timeline of all decisions for a tenant
- **Features**:
  - Date range filtering
  - Decision type filtering (recommendation, remediation, etc.)
  - Operator action filtering (accepted, rejected, deferred)
  - Effectiveness visualization
  - Statistics dashboard (acceptance rate, effectiveness, etc.)

#### 5. Docker Integration
- **Update**: [docker-compose.yml](../docker-compose.yml)
- **New Service**: `decision-audit-service` on port 8004
- **New Database**: MySQL container for decision storage
- **Monitoring**: Prometheus scrape target added to [monitoring/prometheus.yml](../monitoring/prometheus.yml)

## API Reference

### Record a Decision

**POST /decisions**
```json
{
  "tenant_id": "acme-corp",
  "decision_type": "recommendation",
  "source_service": "intelligence",
  "triggering_metrics": {
    "health_score": 65.2,
    "drift_percentage": 15.3
  },
  "evidence": {
    "rules_triggered": ["rule_high_latency"],
    "confidence_factors": [0.92]
  },
  "model_version": "7.3.1",
  "recommendation": "Increase marketplace cache TTL from 5m to 15m",
  "recommendation_detail": {
    "action_type": "configuration_update",
    "parameter": "CACHE_TTL_SECONDS",
    "current_value": 300,
    "recommended_value": 900
  },
  "confidence": 0.92,
  "priority": "high"
}
```

**Response**: `201 Created`
```json
{
  "decision_id": "a1b2c3d4e5f6...",
  "timestamp": "2026-06-26T08:36:40Z",
  "status": "recorded"
}
```

### Record Operator Action

**PATCH /decisions/{decision_id}/action**
```json
{
  "operator_action": "accepted",
  "operator_notes": "Implemented with monitoring",
  "operator_id": "ops-alice"
}
```

### Track Execution

**PATCH /decisions/{decision_id}/execution-status**
```json
{
  "status": "executing"
}
```

After execution:
```json
{
  "status": "completed",
  "error": null
}
```

### Record Effectiveness (24h after execution)

**POST /decisions/{decision_id}/effectiveness**
```json
{
  "health_before": {
    "health_score": 65.2,
    "availability": 99.2,
    "latency_p95_ms": 1245
  },
  "health_after": {
    "health_score": 78.9,
    "availability": 99.8,
    "latency_p95_ms": 642
  },
  "learning_feedback": {
    "feedback_provided": true,
    "rule_effectiveness": 0.92,
    "recommendation_accuracy": 0.88
  }
}
```

### Query Decisions

**GET /decisions?tenant_id=X&start_date=T1&end_date=T2&decision_type=Y**

**GET /timeline?tenant_id=X&start_date=T1&end_date=T2**

**GET /analytics?tenant_id=X**

## Integration Guide

### 1. Integrate with Intelligence Service

In your intelligence recommendation logic:

```php
<?php
require_once __DIR__ . '/../../services/lib/DecisionAuditClient.php';

// When your intelligence engine generates a recommendation:
$decision_id = DecisionAuditClient::recordRecommendation(
    tenant_id: 'acme-corp',
    triggering_metrics: [
        'health_score' => 65.2,
        'drift_percentage' => 15.3,
        'failed_checks' => ['auth_latency', 'marketplace_throughput']
    ],
    evidence: [
        'rules_triggered' => ['rule_high_latency', 'rule_frequent_errors'],
        'anomalies' => [
            ['metric' => 'response_time', 'zscore' => 2.8]
        ]
    ],
    model_version: '7.3.1',
    recommendation: 'Increase marketplace cache TTL to 15 minutes',
    recommendation_detail: [
        'action_type' => 'configuration_update',
        'component' => 'marketplace_service',
        'parameter' => 'CACHE_TTL_SECONDS',
        'current_value' => 300,
        'recommended_value' => 900
    ],
    confidence: 0.92,
    priority: 'high'
);

// Store decision_id for tracking
$_SESSION['last_recommendation_id'] = $decision_id;
```

### 2. Integrate with Operational Readiness

When operational readiness system takes corrective action:

```php
<?php
// Record the remediation decision
$decision_id = DecisionAuditClient::recordRemediation(
    tenant_id: 'acme-corp',
    triggering_metrics: [
        'readiness_score' => 0.72
    ],
    evidence: [
        'failed_checks' => ['health_probe_timeout', 'service_restart_needed']
    ],
    model_version: '7.3.2',
    recommendation: 'Restart marketplace service and clear cache',
    confidence: 0.98,
    priority: 'critical'
);

// Track execution start
DecisionAuditClient::recordExecutionStart($decision_id);

try {
    // Perform remediation
    restartMarketplaceService();
    clearServiceCache();
    
    // Record execution success
    DecisionAuditClient::recordExecutionEnd($decision_id, 'completed');
} catch (Exception $e) {
    // Record execution failure
    DecisionAuditClient::recordExecutionEnd(
        $decision_id,
        'failed',
        $e->getMessage()
    );
}
```

### 3. Track Operator Decisions

In your Operations Center when an operator reviews a recommendation:

```php
<?php
// Operator accepts recommendation
if ($_POST['action'] === 'accept') {
    DecisionAuditClient::recordOperatorAction(
        decision_id: $_POST['decision_id'],
        action: 'accepted',
        notes: $_POST['notes'] ?? null,
        operator_id: $_SESSION['user_id']
    );
    
    // Then execute the recommendation
    DecisionAuditClient::recordExecutionStart($_POST['decision_id']);
    // ... perform action ...
    DecisionAuditClient::recordExecutionEnd($_POST['decision_id'], 'completed');
}

// Operator rejects recommendation
elseif ($_POST['action'] === 'reject') {
    DecisionAuditClient::recordOperatorAction(
        decision_id: $_POST['decision_id'],
        action: 'rejected',
        notes: $_POST['notes'] ?? null,
        operator_id: $_SESSION['user_id']
    );
}
```

### 4. Calculate Effectiveness (24 hours later)

Scheduled job (e.g., cron job) that runs 24 hours after each decision:

```php
<?php
// Get all decisions executed 24 hours ago
$decisions = DecisionAuditDB::getDecisions([
    'execution_status' => 'completed',
    'start_date' => date('c', time() - 90000), // 25 hours ago
    'end_date' => date('c', time() - 86400),   // 24 hours ago
]);

foreach ($decisions as $decision) {
    if ($decision['effectiveness_score'] !== null) {
        continue; // Already calculated
    }
    
    // Get tenant's current health
    $health_before = $decision['health_before'];
    $health_after = getTenantHealth($decision['tenant_id']);
    
    // Calculate effectiveness
    DecisionAuditClient::recordEffectiveness(
        decision_id: $decision['id'],
        health_before: $health_before,
        health_after: $health_after,
        learning_feedback: [
            'feedback_provided' => true,
            'recommendation_accuracy' => calculateAccuracy($decision),
            'operator_confidence' => 'high'
        ]
    );
    
    // Fire event for learning engine to update
    fireEvent('decision.effectiveness_calculated', [
        'decision_id' => $decision['id'],
        'effectiveness_score' => $effectiveness_score
    ]);
}
```

### 5. Feed Back to Learning Engine

When learning engine receives effectiveness feedback:

```php
<?php
// Event handler: decision.effectiveness_calculated
function onDecisionEffectivenessCalculated($event) {
    $decision_id = $event['decision_id'];
    $decision = DecisionAuditClient::getDecision($decision_id);
    
    // Extract learning data
    $learning_data = [
        'decision_type' => $decision['decision_type'],
        'source_service' => $decision['source_service'],
        'rule_effectiveness' => $decision['effectiveness_score'],
        'operator_acceptance' => $decision['operator_action'] === 'accepted' ? 1 : 0,
        'confidence' => $decision['confidence'],
        'sample_date' => $decision['timestamp']
    ];
    
    // Update model confidence in learning engine
    LearningEngine::updateRuleConfidence(
        rule_id: $decision['evidence']['rules_triggered'][0] ?? null,
        effectiveness: $decision['effectiveness_score'],
        sample: $learning_data
    );
}
```

## Startup and Deployment

### 1. Start the Services

```bash
# Start full stack with decision audit layer
docker-compose up -d

# Verify decision audit service
curl http://127.0.0.1:8004/health

# Check database initialization
docker exec gdwb_mysql mysql -u gdwb_user -pgdwb_password -e "USE gdwb_decision_audit; SHOW TABLES;"
```

### 2. Verify Integration

```bash
# Record a test decision
curl -X POST http://127.0.0.1:8004/decisions \
  -H "Content-Type: application/json" \
  -d '{
    "tenant_id": "test-tenant",
    "decision_type": "recommendation",
    "source_service": "intelligence",
    "triggering_metrics": {"health_score": 65.2},
    "evidence": {"rules_triggered": ["test_rule"]},
    "model_version": "7.4.0",
    "recommendation": "Test recommendation",
    "confidence": 0.85,
    "priority": "medium"
  }'

# Get the decision
curl http://127.0.0.1:8004/decisions?tenant_id=test-tenant

# View timeline in Operations Center
# Navigate to: http://127.0.0.1:8006/operations-center/decision-timeline.html
```

### 3. Configure Monitoring

Decision audit metrics are automatically exposed at `/metrics`:
- `decision_audit_decisions_recorded_total` — Total decisions recorded
- `decision_audit_decisions_operator_action_total` — Operator actions
- `decision_audit_decisions_execution_total` — Execution outcomes

Prometheus scrapes these from http://127.0.0.1:8004/metrics

## Compliance & Export

### Export Decision Records

```bash
# Export as JSON
curl "http://127.0.0.1:8004/decisions/abc123/export?format=json" > decision.json

# Export as CSV
curl "http://127.0.0.1:8004/decisions/abc123/export?format=csv" > decision.csv

# Export all decisions for audit
curl "http://127.0.0.1:8004/decisions?tenant_id=acme-corp&format=json" > audit_export.json
```

### Compliance Requirements

The audit trail supports:
- **GDPR**: Tenant and decision filtering for data subject access requests
- **SOC 2**: Complete decision lineage and operator actions with timestamps
- **Incident Investigation**: Causality tracking and decision relationships
- **Governance**: Acceptance rates and effectiveness metrics by rule/service

## Success Criteria

✅ **Sprint 7.4 Complete** when:
- [x] Decision audit service running and healthy
- [x] All decision types recorded (recommendations, remediations, configurations)
- [x] Operator actions tracked in database
- [x] Effectiveness calculations working
- [x] Operations Center timeline UI displaying decisions
- [x] Learning engine feedback loop connected
- [x] Prometheus metrics exposed
- [x] Export functionality working (JSON/CSV)

## Next Steps: Sprint 7.5 — Predictive Intelligence

With decision audit records now available, Sprint 7.5 will implement:

1. **Historical Analysis**: Aggregate decision data by rule, service, and outcome
2. **Pattern Recognition**: Identify which recommendations lead to best outcomes
3. **Forecasting Models**:
   - Tenant health prediction (24h, 72h)
   - Plugin failure probability
   - Drift progression forecasting
   - Remediation success probability

4. **Anomaly Detection**: Identify unusual decision patterns

These capabilities depend directly on the decision audit dataset created in Sprint 7.4.

## Troubleshooting

### Database Connection Issues

```bash
# Check MySQL is running
docker ps | grep mysql

# Check connection
docker exec gdwb_mysql mysql -u gdwb_user -pgdwb_password -e "SELECT 1"

# View logs
docker logs gdwb_decision_audit_service
```

### Service Won't Start

```bash
# Rebuild decision audit service
docker-compose build decision-audit-service

# Force recreation
docker-compose up -d --force-recreate decision-audit-service
```

### Missing Tables

```bash
# Manually initialize schema
docker exec gdwb_mysql mysql -u gdwb_user -pgdwb_password gdwb_decision_audit < services/decision-audit/schema.sql
```

## Performance Considerations

- **Indexes**: Created on tenant_id, timestamp, decision_type for fast queries
- **Retention**: Default 2 years; configure with `DECISION_RETENTION_DAYS` env var
- **Batch Operations**: Consider batching multiple decision records in high-volume scenarios
- **Analytics**: Pre-calculated daily snapshots in `decision_analytics` table

## Security

- All decision records immutable after creation (no updates to core fields)
- Operator actions tracked with operator_id and timestamp
- Access control should be enforced at application layer (not implemented in 7.4)
- Export records include audit trail in `decision_exports` table

---

**Sprint 7.4 Complete** — The platform now has complete decision traceability, operator auditability, and the historical dataset needed for predictive intelligence in Sprint 7.5.
