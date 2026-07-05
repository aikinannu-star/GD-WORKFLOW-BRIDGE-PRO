/**
 * CI Recovery Gate
 * Evaluates recovery metrics against SLAs and determines gate outcome
 */

const fs = require('fs');
const path = require('path');
const { extractRecoveryMetrics, computeRtoRpo, DEFAULT_THRESHOLDS } = require('./recovery/recovery-metrics');

(async () => {
  const resultsDir = process.argv[2] || path.join(__dirname, 'results');
  
  if (!fs.existsSync(resultsDir)) {
    console.log('RECOVERY GATE: No results directory found, skipping');
    process.exit(0);
  }

  try {
    const recoveryData = extractRecoveryMetrics(resultsDir);
    const metrics = computeRtoRpo(recoveryData, DEFAULT_THRESHOLDS);

    console.log('');
    console.log('╔════════════════════════════════════════╗');
    console.log('║      RECOVERY GATE EVALUATION          ║');
    console.log('╚════════════════════════════════════════╝');
    console.log('');
    console.log(`Average RTO:        ${(metrics.avg_rto_ms / 1000).toFixed(1)}s (target < 60s) ${metrics.avg_rto_ms > 60000 ? '❌ FAIL' : '✅ PASS'}`);
    console.log(`P95 RTO:            ${(metrics.p95_rto_ms / 1000).toFixed(1)}s (target < 90s) ${metrics.p95_rto_ms > 90000 ? '⚠️  WARN' : '✅ PASS'}`);
    console.log(`Average RPO:        ${(metrics.avg_rpo_ms / 1000).toFixed(1)}s (target < 30s) ${metrics.avg_rpo_ms > 30000 ? '❌ FAIL' : '✅ PASS'}`);
    console.log(`Recovery Success:   ${(metrics.recovery_success_rate*100).toFixed(1)}% (target > 99%) ${metrics.recovery_success_rate < 0.99 ? '❌ FAIL' : '✅ PASS'}`);
    console.log(`Worst-case RTO:     ${(metrics.max_rto_ms / 1000).toFixed(1)}s (informational)`);
    console.log('');
    console.log(`Recovery Status: ${metrics.severity === 'pass' ? '✅ PASS' : (metrics.severity === 'warn' ? '⚠️  WARN' : '❌ FAIL')}`);
    console.log('');

    // Write metrics to file for reporting
    const gateFile = path.join(resultsDir, 'recovery-gate.json');
    fs.writeFileSync(gateFile, JSON.stringify({ 
      recovery: metrics,
      thresholds: DEFAULT_THRESHOLDS,
      evaluated_at: new Date().toISOString()
    }, null, 2));

    // Exit code: 0 = pass, 1 = fail, 2 = warn (non-blocking)
    if (metrics.severity === 'fail') {
      console.error('Recovery SLA not met');
      process.exit(1);
    } else if (metrics.severity === 'warn') {
      console.warn('Recovery metrics in warning state');
      process.exit(0); // warn but don't fail CI
    } else {
      console.log('All recovery targets met');
      process.exit(0);
    }
  } catch (err) {
    console.error('Error evaluating recovery gate:', err.message);
    process.exit(1);
  }
})();
