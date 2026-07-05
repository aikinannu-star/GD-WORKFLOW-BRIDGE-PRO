# Sprint 7.4 — Decision Audit Layer Architecture

## Overview

The Decision Audit Layer creates an immutable historical record of every recommendation, remediation, and platform decision. It transforms the intelligence engine from a black box into an auditable, explainable system and provides the historical dataset required for predictive intelligence in Sprint 7.5.

## Strategic Value

**For Governance**:
- Answer "Why did the platform recommend this?" with complete evidence
- Track operator acceptance/rejection rates and patterns
- Export audit trails for compliance and incident investigation

**For Learning**:
- Measure recommendation accuracy and effectiveness
- Identify which rules produce the best outcomes
- Feed effectiveness scores back into model training

**For Prediction** (Sprint 7.5):
- Analyze historical patterns to forecast tenant health
- Predict plugin failure probability
- Anticipate drift and capacity issues

**For Operations**:
- Timeline view of all platform decisions per tenant
- Correlation between decisions and health improvements
- Trend analysis for decision acceptance and effectiveness

## Architecture

### Service Layer

**Decision Audit Service** (New microservice on port 8004)

Responsibilities:
- Persist every recommendation, remediation, and platform decision
- Provide queryable audit record
- Calculate effectiveness scores
- Export audit data for compliance
- Fire events for downstream consumers (learning engine, dashboards)

```
Intelligence Service     Operational Readiness    Learning Engine
       ↓                        ↓                        ↓
    (emits decision)      (emits decision)         (emits decision)
       ↓                        ↓                        ↓
       └─────────────┬──────────┘─────────┬──────────┘
                     │                    │
                     v                    v
           Decision Audit Service
                     │
      ┌──────────────┼──────────────┐
      ↓              ↓              ↓
   Audit DB    Effectiveness   Event Stream
               Calculations    (for dashboards)
      ↓
 Operations Center
 Decision Timeline UI
```

### Data Model

```yaml
Decision Record:
  decision_id:          # UUID, unique identifier
    type: string
    required: true
    format: uuid

  timestamp:            # When decision was made
    type: string
    format: rfc3339
    required: true

  tenant_id:            # Affected tenant
    type: string
    required: true

  decision_type:        # recommendation | remediation | configuration | learning_update
    type: enum
    required: true

  source_service:       # Where decision originated
    type: string        # intelligence | operational_readiness | learning | governance
    required: true

  triggering_metrics:   # What prompted the decision (JSON)
    type: object
    example:
      health_score: 65.2
      drift_percentage: 15.3
      failed_checks: ["auth_latency", "marketplace_throughput"]
      tenant_name: "acme-corp"

  evidence:             # Supporting data (JSON)
    type: object
    example:
      rules_triggered: ["rule_high_latency", "rule_frequent_errors"]
      data_points: 1247
      confidence_factors: [0.92, 0.87, 0.91]
      anomalies: [{"metric": "response_time", "zscore": 2.8}]

  model_version:        # Rules/model version that produced decision
    type: string
    required: true

  recommendation:       # The actual recommendation or action
    type: string
    required: true
    example: "Increase marketplace cache TTL from 5m to 15m"

  recommendation_detail: # Structured data about recommendation
    type: object
    example:
      action_type: "configuration_update"
      affected_component: "marketplace_service"
      parameter: "CACHE_TTL_SECONDS"
      current_value: 300
      recommended_value: 900
      rationale: "Current TTL insufficient for peak traffic patterns"

  confidence:           # Confidence level [0.0, 1.0]
    type: number
    required: true

  priority:             # critical | high | medium | low
    type: enum
    required: true

  operator_action:      # What the operator did
    type: enum          # accepted | rejected | deferred | overridden
    default: null       # Null until operator acts

  operator_notes:       # Why operator accepted/rejected
    type: string
    nullable: true

  operator_timestamp:   # When operator acted
    type: string
    format: rfc3339
    nullable: true

  execution_start:      # When recommendation was executed
    type: string
    format: rfc3339
    nullable: true

  execution_end:        # When execution completed
    type: string
    format: rfc3339
    nullable: true

  execution_status:     # pending | executing | completed | failed
    type: enum
    default: pending

  execution_error:      # Error message if execution failed
    type: string
    nullable: true

  effectiveness_score:  # Did it improve health? [0.0, 1.0]
    type: number
    nullable: true       # Populated 24 hours after execution

  health_before:        # Health metrics before execution
    type: object
    nullable: true
    example:
      health_score: 65.2
      availability: 99.2
      latency_p95_ms: 1245
      error_rate: 0.034

  health_after:         # Health metrics 24 hours after execution
    type: object
    nullable: true
    example:
      health_score: 78.9
      availability: 99.8
      latency_p95_ms: 642
      error_rate: 0.008

  learning_feedback:    # Feedback for learning engine
    type: object
    nullable: true
    example:
      feedback_provided: true
      feedback_timestamp: "2026-06-27T14:32:00Z"
      rule_effectiveness: 0.92
      recommendation_accuracy: 0.88
      operator_confidence: "high"
```

### Database Schema

```sql
CREATE TABLE decisions (
  id VARCHAR(36) PRIMARY KEY,
  tenant_id VARCHAR(255) NOT NULL,
  timestamp DATETIME NOT NULL,
  decision_type ENUM('recommendation', 'remediation', 'configuration', 'learning_update') NOT NULL,
  source_service VARCHAR(100) NOT NULL,
  triggering_metrics JSON NOT NULL,
  evidence JSON NOT NULL,
  model_version VARCHAR(50) NOT NULL,
  recommendation TEXT NOT NULL,
  recommendation_detail JSON,
  confidence DECIMAL(3, 2) NOT NULL CHECK (confidence BETWEEN 0 AND 1),
  priority ENUM('critical', 'high', 'medium', 'low') NOT NULL,
  operator_action ENUM('accepted', 'rejected', 'deferred', 'overridden'),
  operator_notes TEXT,
  operator_timestamp DATETIME,
  execution_start DATETIME,
  execution_end DATETIME,
  execution_status ENUM('pending', 'executing', 'completed', 'failed') DEFAULT 'pending',
  execution_error TEXT,
  effectiveness_score DECIMAL(3, 2) CHECK (effectiveness_score IS NULL OR effectiveness_score BETWEEN 0 AND 1),
  health_before JSON,
  health_after JSON,
  learning_feedback JSON,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_timestamp (timestamp),
  INDEX idx_decision_type (decision_type),
  INDEX idx_source_service (source_service),
  INDEX idx_operator_action (operator_action),
  INDEX idx_effectiveness (effectiveness_score),
  FULLTEXT INDEX ft_recommendation (recommendation)
);

CREATE TABLE decision_relationships (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_decision_id VARCHAR(36) NOT NULL,
  child_decision_id VARCHAR(36) NOT NULL,
  relationship_type ENUM('caused', 'related_to', 'dependency', 'contradicts') NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (parent_decision_id) REFERENCES decisions(id),
  FOREIGN KEY (child_decision_id) REFERENCES decisions(id),
  INDEX idx_parent (parent_decision_id),
  INDEX idx_child (child_decision_id)
);

CREATE TABLE decision_exports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  decision_id VARCHAR(36) NOT NULL,
  export_format ENUM('json', 'csv', 'pdf') NOT NULL,
  export_purpose VARCHAR(255),
  exported_by VARCHAR(255),
  export_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
  file_path VARCHAR(500),
  
  FOREIGN KEY (decision_id) REFERENCES decisions(id),
  INDEX idx_decision (decision_id),
  INDEX idx_timestamp (export_timestamp)
);
```

### API Endpoints

**Decision Recording**:
```
POST /decisions
{
  tenant_id, decision_type, source_service, triggering_metrics,
  evidence, model_version, recommendation, recommendation_detail,
  confidence, priority
}
→ 201 { decision_id, timestamp }
```

**Decision Query**:
```
GET /decisions?tenant_id=X&decision_type=Y&start=T1&end=T2&limit=50
→ 200 { decisions: [...] }

GET /decisions/{decision_id}
→ 200 { decision }

GET /decisions/{decision_id}/timeline
→ 200 { decision, related_decisions: [...], outcomes: {...} }
```

**Operator Action**:
```
PATCH /decisions/{decision_id}/action
{
  operator_action: "accepted|rejected|deferred|overridden",
  operator_notes?: "optional explanation"
}
→ 200 { decision_id, updated_at }

PATCH /decisions/{decision_id}/execution-status
{
  status: "executing|completed|failed",
  error?: "error message if failed"
}
→ 200 { decision_id, status }
```

**Effectiveness Feedback**:
```
POST /decisions/{decision_id}/effectiveness
{
  effectiveness_score: 0.92,
  health_before: {...},
  health_after: {...},
  learning_feedback: {...}
}
→ 200 { decision_id, effectiveness_score }
```

**Export & Analytics**:
```
GET /decisions/{decision_id}/export?format=json|csv|pdf
→ 200 { exported data in specified format }

GET /analytics/effectiveness
?tenant_id=X&start=T1&end=T2&group_by=decision_type|source_service
→ 200 {
    total_decisions: 127,
    acceptance_rate: 0.89,
    effectiveness_avg: 0.84,
    by_type: {...},
    trends: [...]
  }

GET /analytics/decision-trends
?metric=acceptance_rate|effectiveness|confidence
→ 200 { time_series: [...] }
```

### Event Stream

When decisions are recorded/updated, fire events:

```
decision.recorded
  → { decision_id, tenant_id, decision_type, source_service, timestamp }

decision.operator_acted
  → { decision_id, tenant_id, operator_action, timestamp }

decision.execution_completed
  → { decision_id, tenant_id, effectiveness_score, timestamp }

decision.feedback_received
  → { decision_id, tenant_id, learning_feedback, timestamp }
```

These events flow to:
- **Learning Engine** — Adjust model based on effectiveness
- **Observability** — Dashboards for decision visualization
- **Operational Readiness** — Track decision outcomes for health scoring

### Integration Points

**From Intelligence Service**:
When intelligence engine makes a recommendation:
```php
DecisionAudit::recordDecision(
    tenant_id: 'acme-corp',
    decision_type: 'recommendation',
    source_service: 'intelligence',
    triggering_metrics: [health_score => 65.2, drift => 15.3],
    evidence: [rules_triggered => [...], confidence => 0.91],
    model_version: '7.2.1',
    recommendation: 'Increase cache TTL',
    confidence: 0.91,
    priority: 'high'
);
```

**From Operational Readiness**:
When readiness engine takes corrective action:
```php
DecisionAudit::recordDecision(
    tenant_id: 'acme-corp',
    decision_type: 'remediation',
    source_service: 'operational_readiness',
    triggering_metrics: [readiness_score => 0.72],
    evidence: [failed_checks => [...]],
    model_version: '7.3.2',
    recommendation: 'Restart marketplace service',
    confidence: 0.98,
    priority: 'critical'
);
```

**From Learning Engine**:
When learning engine updates rules/models:
```php
DecisionAudit::recordDecision(
    tenant_id: null,  // platform-wide
    decision_type: 'learning_update',
    source_service: 'learning',
    triggering_metrics: [...],
    evidence: [decision_effectiveness => [...], feedback_received => 156],
    model_version: '7.3.3',
    recommendation: 'Updated rule_high_latency with new thresholds',
    confidence: 0.87,
    priority: 'medium'
);
```

**From Operations Center**:
Operator accepts or rejects recommendation:
```
PATCH /decisions/{id}/action
{ operator_action: 'accepted', operator_notes: 'Monitoring needed' }
```

Then:
```php
// In intelligence/operations service:
DecisionAudit::recordOperatorAction($decision_id, 'accepted');
DecisionAudit::recordExecutionStart($decision_id);
// ... perform execution ...
DecisionAudit::recordExecutionStatus($decision_id, 'completed');
```

After 24 hours, effectiveness calculation:
```php
DecisionAudit::calculateEffectiveness($decision_id, $health_before, $health_after);
// Fires decision.effectiveness_calculated event
// Learning engine subscribes and updates confidence in recommendations
```

### Operations Center UI Component

Location: [services/marketplace/public/operations-center/decision-timeline.html](services/marketplace/public/operations-center/decision-timeline.html)

Displays:
- **Decision Timeline** — Chronological view of all decisions for selected tenant
- **Decision Details** — Full record including evidence, metrics, operator action
- **Effectiveness Graph** — Shows health before/after for each decision
- **Trend Analysis** — Acceptance rate, effectiveness, confidence over time
- **Audit Export** — Download decision records for compliance

Integration:
```
Decision Audit Service
        ↓
GET /decisions?tenant_id=X (filtered by date range)
        ↓
Decision Timeline UI displays:
  - Decision timestamp, type, recommendation
  - Evidence and metrics that triggered it
  - Operator action (accepted/rejected/etc)
  - Execution status
  - Effectiveness score
  - Related decisions (causality chain)
```

### Effectiveness Calculation Algorithm

24 hours after decision execution:

```
Effectiveness Score = 
  0.3 * health_improvement_factor +
  0.3 * confidence_alignment +
  0.2 * operator_confidence +
  0.2 * trend_continuation

where:
  health_improvement_factor = (health_after - health_before) / baseline_volatility
  confidence_alignment = 1.0 - abs(predicted_improvement - actual_improvement)
  operator_confidence = 1.0 if accepted, 0.5 if deferred, 0.0 if rejected
  trend_continuation = 1.0 if improvement continues, 0.5 if plateau, 0.0 if reversal
```

### Learning Feedback Loop

```
Decision Audit Records
        ↓
Aggregate by decision_type, source_service
        ↓
Calculate effectiveness distribution
        ↓
Identify patterns (which rules work best)
        ↓
Learning Engine receives:
  {
    rule_id: "rule_high_latency",
    effectiveness_distribution: {avg: 0.92, p50: 0.94, p10: 0.67},
    recommendation_accuracy: 0.89,
    operator_acceptance_rate: 0.91,
    sample_size: 487
  }
        ↓
Learning Engine adjusts:
  - Confidence thresholds
  - Rule trigger points
  - Recommendation priorities
        ↓
Updates model_version and publishes
```

## Implementation Phases

### Phase 1: Core Service & Database (Sprint 7.4a)
- Decision Audit service on port 8004
- Database schema and migrations
- Core APIs (record, query, operator action)
- Integration with intelligence service

### Phase 2: Effectiveness & Feedback (Sprint 7.4b)
- Effectiveness calculation at 24h mark
- Feedback loop to learning engine
- Analytics endpoints
- Event stream to observability

### Phase 3: Operations Center UI (Sprint 7.4c)
- Decision timeline component
- Effectiveness visualization
- Trend analysis dashboard
- Export functionality

### Phase 4: Advanced Features (Sprint 7.4d)
- Decision relationship tracking
- Causality analysis
- Compliance report generation
- Predictive outcome modeling (prep for 7.5)

## Migration Path

**Historical Data** (Optional, Phase 2+):
- Can retroactively import decisions from intelligence service logs
- Tag with `imported_from_logs: true` for tracking
- Enables analytics on historical decisions
- Not required for 7.4 — focus on forward-looking audit trail

## Production Readiness Checklist

- [ ] Database with proper backup strategy
- [ ] API rate limiting and authentication
- [ ] Event stream reliability (at-least-once delivery)
- [ ] Audit log immutability (no updates after initial record)
- [ ] Data retention policy (e.g., 2 years)
- [ ] GDPR/privacy compliance for exported data
- [ ] Encryption at rest and in transit
- [ ] Access control (who can query decisions)
- [ ] Performance optimization for large tenants (1000+ decisions/day)

## Success Criteria

By end of Sprint 7.4:
- Every recommendation produces an auditable record ✓
- Operators can accept/reject/defer decisions ✓
- Effectiveness is calculated 24h after execution ✓
- Operations Center shows decision timeline and effectiveness ✓
- Learning engine receives feedback loop ✓
- Compliance exports work (JSON/CSV/PDF) ✓
- Platform maturity increases to 9.7/10 ✓

## Next: Sprint 7.5 — Predictive Intelligence

With historical audit data:
- Forecast tenant health in 24/72 hours
- Predict plugin failure probability
- Anticipate drift progression
- Estimate remediation success probability
- Anomaly detection for unexpected patterns

These require the decision audit records as training data.
