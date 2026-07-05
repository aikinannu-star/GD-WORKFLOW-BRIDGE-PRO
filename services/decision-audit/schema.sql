-- Decision Audit Layer Database Schema
-- Sprint 7.4: Comprehensive audit trail for all platform recommendations and decisions

CREATE TABLE IF NOT EXISTS decisions (
  id VARCHAR(36) PRIMARY KEY COMMENT 'UUID, unique decision identifier',
  tenant_id VARCHAR(255) NOT NULL COMMENT 'Tenant that decision affects',
  timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When decision was made',
  decision_type ENUM('recommendation', 'remediation', 'configuration', 'learning_update') NOT NULL COMMENT 'Type of decision',
  source_service VARCHAR(100) NOT NULL COMMENT 'Service that originated decision',
  triggering_metrics JSON NOT NULL COMMENT 'Metrics that prompted decision',
  evidence JSON NOT NULL COMMENT 'Supporting data and analysis',
  model_version VARCHAR(50) NOT NULL COMMENT 'Version of rules/model that produced decision',
  recommendation TEXT NOT NULL COMMENT 'The actual recommendation or action',
  recommendation_detail JSON COMMENT 'Structured data about recommendation',
  confidence DECIMAL(3, 2) NOT NULL COMMENT 'Confidence [0.0, 1.0]',
  priority ENUM('critical', 'high', 'medium', 'low') NOT NULL DEFAULT 'medium' COMMENT 'Priority level',
  
  -- Operator Response
  operator_action ENUM('accepted', 'rejected', 'deferred', 'overridden') COMMENT 'Operator action on recommendation',
  operator_notes TEXT COMMENT 'Operator explanation',
  operator_timestamp DATETIME COMMENT 'When operator acted',
  operator_id VARCHAR(255) COMMENT 'ID of operator who acted',
  
  -- Execution Tracking
  execution_start DATETIME COMMENT 'When execution started',
  execution_end DATETIME COMMENT 'When execution completed',
  execution_status ENUM('pending', 'executing', 'completed', 'failed') DEFAULT 'pending' COMMENT 'Execution status',
  execution_error TEXT COMMENT 'Error message if execution failed',
  
  -- Effectiveness Measurement
  effectiveness_score DECIMAL(3, 2) COMMENT 'Effectiveness [0.0, 1.0], calculated 24h after execution',
  health_before JSON COMMENT 'Health metrics before execution',
  health_after JSON COMMENT 'Health metrics 24h after execution',
  
  -- Learning Feedback
  learning_feedback JSON COMMENT 'Feedback for learning engine',
  
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  CONSTRAINT chk_confidence CHECK (confidence BETWEEN 0 AND 1),
  CONSTRAINT chk_effectiveness CHECK (effectiveness_score IS NULL OR effectiveness_score BETWEEN 0 AND 1),
  
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_timestamp (timestamp),
  INDEX idx_decision_type (decision_type),
  INDEX idx_source_service (source_service),
  INDEX idx_operator_action (operator_action),
  INDEX idx_effectiveness (effectiveness_score),
  INDEX idx_confidence (confidence),
  INDEX idx_priority (priority),
  INDEX idx_tenant_timestamp (tenant_id, timestamp),
  INDEX idx_source_timestamp (source_service, timestamp),
  INDEX idx_execution_status (execution_status),
  FULLTEXT INDEX ft_recommendation (recommendation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for all platform decisions';

CREATE TABLE IF NOT EXISTS decision_relationships (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_decision_id VARCHAR(36) NOT NULL COMMENT 'Upstream decision',
  child_decision_id VARCHAR(36) NOT NULL COMMENT 'Downstream decision',
  relationship_type ENUM('caused', 'related_to', 'dependency', 'contradicts') NOT NULL COMMENT 'Type of relationship',
  explanation TEXT COMMENT 'Why these decisions are related',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  
  CONSTRAINT fk_parent FOREIGN KEY (parent_decision_id) REFERENCES decisions(id) ON DELETE CASCADE,
  CONSTRAINT fk_child FOREIGN KEY (child_decision_id) REFERENCES decisions(id) ON DELETE CASCADE,
  
  INDEX idx_parent (parent_decision_id),
  INDEX idx_child (child_decision_id),
  INDEX idx_relationship_type (relationship_type),
  UNIQUE KEY uk_relationship (parent_decision_id, child_decision_id, relationship_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Causality and correlation between decisions';

CREATE TABLE IF NOT EXISTS decision_exports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  decision_id VARCHAR(36) NOT NULL COMMENT 'Decision being exported',
  export_format ENUM('json', 'csv', 'pdf') NOT NULL COMMENT 'Export format',
  export_purpose VARCHAR(255) COMMENT 'Reason for export',
  exported_by VARCHAR(255) COMMENT 'User who requested export',
  export_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
  file_path VARCHAR(500) COMMENT 'Path to exported file',
  file_hash VARCHAR(64) COMMENT 'SHA256 hash of file for integrity',
  
  CONSTRAINT fk_decision FOREIGN KEY (decision_id) REFERENCES decisions(id) ON DELETE CASCADE,
  
  INDEX idx_decision (decision_id),
  INDEX idx_timestamp (export_timestamp),
  INDEX idx_format (export_format)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for decision exports';

CREATE TABLE IF NOT EXISTS decision_analytics (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id VARCHAR(255) NOT NULL COMMENT 'Tenant analytics are for',
  period_date DATE NOT NULL COMMENT 'Date of analytics period',
  total_decisions INT DEFAULT 0,
  acceptance_rate DECIMAL(4, 3) COMMENT 'Percentage of accepted decisions',
  rejection_rate DECIMAL(4, 3) COMMENT 'Percentage of rejected decisions',
  deferral_rate DECIMAL(4, 3) COMMENT 'Percentage of deferred decisions',
  avg_effectiveness DECIMAL(3, 2) COMMENT 'Average effectiveness score',
  avg_confidence DECIMAL(3, 2) COMMENT 'Average recommendation confidence',
  decisions_by_type JSON COMMENT 'Count by decision type',
  decisions_by_source JSON COMMENT 'Count by source service',
  recommendations_implemented INT DEFAULT 0 COMMENT 'Count of executed recommendations',
  avg_time_to_action INT COMMENT 'Average seconds from recommendation to operator action',
  
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  INDEX idx_tenant_date (tenant_id, period_date),
  INDEX idx_period_date (period_date),
  UNIQUE KEY uk_tenant_period (tenant_id, period_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pre-calculated analytics for decision data';

-- Stored Procedures for Common Operations

DELIMITER //

CREATE PROCEDURE sp_record_decision (
  IN p_tenant_id VARCHAR(255),
  IN p_decision_type VARCHAR(50),
  IN p_source_service VARCHAR(100),
  IN p_triggering_metrics JSON,
  IN p_evidence JSON,
  IN p_model_version VARCHAR(50),
  IN p_recommendation TEXT,
  IN p_recommendation_detail JSON,
  IN p_confidence DECIMAL(3, 2),
  IN p_priority VARCHAR(20),
  OUT p_decision_id VARCHAR(36)
)
READS SQL DATA DETERMINISTIC
BEGIN
  SET p_decision_id = UUID();
  
  INSERT INTO decisions (
    id, tenant_id, timestamp, decision_type, source_service,
    triggering_metrics, evidence, model_version, recommendation,
    recommendation_detail, confidence, priority, execution_status
  ) VALUES (
    p_decision_id, p_tenant_id, NOW(), p_decision_type, p_source_service,
    p_triggering_metrics, p_evidence, p_model_version, p_recommendation,
    p_recommendation_detail, p_confidence, p_priority, 'pending'
  );
END //

CREATE PROCEDURE sp_calculate_effectiveness (
  IN p_decision_id VARCHAR(36),
  IN p_health_before JSON,
  IN p_health_after JSON,
  IN p_operator_confidence DECIMAL(3, 2)
)
MODIFIES SQL DATA DETERMINISTIC
BEGIN
  DECLARE health_improvement DECIMAL(5, 2);
  DECLARE confidence_alignment DECIMAL(3, 2);
  DECLARE trend_factor DECIMAL(3, 2);
  DECLARE effectiveness DECIMAL(3, 2);
  
  -- Calculate health improvement factor
  SET health_improvement = CAST(
    JSON_EXTRACT(p_health_after, '$.health_score') AS DECIMAL(5, 2)
  ) - CAST(
    JSON_EXTRACT(p_health_before, '$.health_score') AS DECIMAL(5, 2)
  );
  
  -- Normalize improvement (cap at 1.0)
  SET health_improvement = LEAST(health_improvement / 20, 1.0);
  
  -- Calculate overall effectiveness: 0.3*improvement + 0.7*confidence
  SET effectiveness = (health_improvement * 0.3) + (p_operator_confidence * 0.7);
  SET effectiveness = LEAST(GREATEST(effectiveness, 0), 1);
  
  UPDATE decisions SET
    effectiveness_score = effectiveness,
    health_before = p_health_before,
    health_after = p_health_after,
    updated_at = NOW()
  WHERE id = p_decision_id;
END //

CREATE PROCEDURE sp_get_decision_timeline (
  IN p_tenant_id VARCHAR(255),
  IN p_start_date DATETIME,
  IN p_end_date DATETIME,
  IN p_limit INT
)
READS SQL DATA DETERMINISTIC
BEGIN
  SELECT
    d.id,
    d.timestamp,
    d.decision_type,
    d.source_service,
    d.recommendation,
    d.confidence,
    d.priority,
    d.operator_action,
    d.execution_status,
    d.effectiveness_score,
    COUNT(dr.id) as related_decisions
  FROM decisions d
  LEFT JOIN decision_relationships dr ON d.id = dr.parent_decision_id
  WHERE d.tenant_id = p_tenant_id
    AND d.timestamp BETWEEN p_start_date AND p_end_date
  GROUP BY d.id
  ORDER BY d.timestamp DESC
  LIMIT p_limit;
END //

CREATE PROCEDURE sp_update_decision_analytics ()
MODIFIES SQL DATA DETERMINISTIC
BEGIN
  INSERT INTO decision_analytics (
    tenant_id, period_date, total_decisions, acceptance_rate,
    rejection_rate, deferral_rate, avg_effectiveness, avg_confidence,
    decisions_by_type, decisions_by_source, recommendations_implemented,
    avg_time_to_action
  )
  SELECT
    d.tenant_id,
    DATE(d.timestamp),
    COUNT(d.id),
    SUM(CASE WHEN d.operator_action = 'accepted' THEN 1 ELSE 0 END) / COUNT(d.id),
    SUM(CASE WHEN d.operator_action = 'rejected' THEN 1 ELSE 0 END) / COUNT(d.id),
    SUM(CASE WHEN d.operator_action = 'deferred' THEN 1 ELSE 0 END) / COUNT(d.id),
    AVG(d.effectiveness_score),
    AVG(d.confidence),
    JSON_OBJECT(
      'recommendation', SUM(CASE WHEN d.decision_type = 'recommendation' THEN 1 ELSE 0 END),
      'remediation', SUM(CASE WHEN d.decision_type = 'remediation' THEN 1 ELSE 0 END),
      'configuration', SUM(CASE WHEN d.decision_type = 'configuration' THEN 1 ELSE 0 END),
      'learning_update', SUM(CASE WHEN d.decision_type = 'learning_update' THEN 1 ELSE 0 END)
    ),
    JSON_OBJECT(
      'intelligence', SUM(CASE WHEN d.source_service = 'intelligence' THEN 1 ELSE 0 END),
      'operational_readiness', SUM(CASE WHEN d.source_service = 'operational_readiness' THEN 1 ELSE 0 END),
      'learning', SUM(CASE WHEN d.source_service = 'learning' THEN 1 ELSE 0 END),
      'governance', SUM(CASE WHEN d.source_service = 'governance' THEN 1 ELSE 0 END)
    ),
    SUM(CASE WHEN d.execution_status = 'completed' THEN 1 ELSE 0 END),
    AVG(TIMESTAMPDIFF(SECOND, d.timestamp, d.operator_timestamp))
  FROM decisions d
  WHERE DATE(d.timestamp) = CURDATE()
  GROUP BY d.tenant_id, DATE(d.timestamp)
  ON DUPLICATE KEY UPDATE
    total_decisions = VALUES(total_decisions),
    acceptance_rate = VALUES(acceptance_rate),
    rejection_rate = VALUES(rejection_rate),
    deferral_rate = VALUES(deferral_rate),
    avg_effectiveness = VALUES(avg_effectiveness),
    avg_confidence = VALUES(avg_confidence),
    decisions_by_type = VALUES(decisions_by_type),
    decisions_by_source = VALUES(decisions_by_source),
    recommendations_implemented = VALUES(recommendations_implemented),
    avg_time_to_action = VALUES(avg_time_to_action),
    updated_at = NOW();
END //

DELIMITER ;
