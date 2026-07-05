const fs = require('fs');
const path = require('path');

/**
 * Recovery Metrics Aggregator
 * Computes RTO/RPO from backup/restore test results
 */

const DEFAULT_THRESHOLDS = {
  avg_rto_target_ms: 60000,      // 60s - target, fail if exceeded
  p95_rto_target_ms: 90000,      // 90s - warn if exceeded
  avg_rpo_target_ms: 30000,      // 30s - target, fail if exceeded
  recovery_success_rate_target: 0.99  // 99% - fail if below
};

function extractRecoveryMetrics(resultsDir) {
  const backupFile = path.join(resultsDir, 'backup-test.json');
  const restoreFile = path.join(resultsDir, 'restore-test.json');
  
  const metrics = {
    backups: [],
    restores: [],
    corruptions: []
  };

  if (fs.existsSync(backupFile)) {
    const data = JSON.parse(fs.readFileSync(backupFile, 'utf8'));
    metrics.backups.push({
      duration_ms: data.backup_duration_ms || 0,
      status: data.status,
      timestamp: data.backup_completed_at
    });
  }

  if (fs.existsSync(restoreFile)) {
    const data = JSON.parse(fs.readFileSync(restoreFile, 'utf8'));
    metrics.restores.push({
      duration_ms: data.restore_duration_ms || 0,
      status: data.status,
      timestamp: data.restore_completed_at
    });
  }

  return metrics;
}

function computeRtoRpo(metrics, thresholds = DEFAULT_THRESHOLDS) {
  const results = {
    avg_rto_ms: 0,
    p95_rto_ms: 0,
    max_rto_ms: 0,
    avg_rpo_ms: 0,
    recovery_success_rate: 1.0,
    severity: 'pass'
  };

  // RTO: restore duration
  if (metrics.restores.length > 0) {
    const durations = metrics.restores.map(r => r.duration_ms);
    results.avg_rto_ms = Math.round(durations.reduce((a, b) => a + b) / durations.length);
    results.max_rto_ms = Math.max(...durations);
    durations.sort((a, b) => a - b);
    results.p95_rto_ms = durations[Math.floor(durations.length * 0.95)] || results.max_rto_ms;
  }

  // RPO: backup duration (time between backup start and completion = data loss window)
  if (metrics.backups.length > 0) {
    const durations = metrics.backups.map(b => b.duration_ms);
    results.avg_rpo_ms = Math.round(durations.reduce((a, b) => a + b) / durations.length);
  }

  // Recovery success rate
  const totalRecoveries = metrics.backups.length + metrics.restores.length;
  const successCount = metrics.backups.filter(b => b.status === 'pass').length + 
                       metrics.restores.filter(r => r.status === 'pass').length;
  results.recovery_success_rate = totalRecoveries > 0 ? successCount / totalRecoveries : 1.0;

  // Determine severity
  let severity = 'pass';
  if (results.avg_rto_ms > thresholds.avg_rto_target_ms) {
    severity = 'fail';
  } else if (results.p95_rto_ms > thresholds.p95_rto_target_ms) {
    severity = 'warn';
  }
  
  if (results.avg_rpo_ms > thresholds.avg_rpo_target_ms) {
    severity = severity === 'fail' ? 'fail' : 'fail';
  }

  if (results.recovery_success_rate < thresholds.recovery_success_rate_target) {
    severity = 'fail';
  }

  results.severity = severity;
  return results;
}

module.exports = {
  extractRecoveryMetrics,
  computeRtoRpo,
  DEFAULT_THRESHOLDS
};
