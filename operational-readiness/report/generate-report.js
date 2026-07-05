const fs = require('fs');
const path = require('path');
const { extractRecoveryMetrics, computeRtoRpo } = require('../recovery/recovery-metrics');

module.exports = function(summaryFile, outHtml){
  const summary = JSON.parse(fs.readFileSync(summaryFile,'utf8'));
  const resultsDir = path.dirname(summaryFile);

  // Extract and compute recovery metrics
  const recoveryData = extractRecoveryMetrics(resultsDir);
  const recoveryMetrics = computeRtoRpo(recoveryData);

  // compute readiness score components
  const comps = { load: [], security: [], recovery: [], observability: [] };
  const resultsMap = {};
  for(const s of summary){
    resultsMap[s.test] = s;
    if(s.test.startsWith('tenant-load') || s.test.startsWith('marketplace') || s.test.startsWith('analytics')) comps.load.push(s);
    if(s.test.startsWith('tenant-isolation') || s.test.startsWith('auth-matrix') || s.test.startsWith('remediation-permissions')) comps.security.push(s);
    if(s.test.startsWith('backup') || s.test.startsWith('restore') || s.test.startsWith('corruption')) comps.recovery.push(s);
    if(s.test.startsWith('metrics') || s.test.startsWith('health')) comps.observability.push(s);
  }

  function scoreBucket(arr){
    if(arr.length===0) return 100;
    let score=0; let count=0;
    for(const a of arr){
      const s = a.status;
      if(s==='pass') score+=100;
      else if(s==='warn') score+=70;
      else if(s==='skipped') score+=85;
      else score+=0;
      count++;
    }
    return Math.round(score/count);
  }

  const loadScore = scoreBucket(comps.load);
  const securityScore = scoreBucket(comps.security);
  const recoveryScore = recoveryMetrics.severity === 'pass' ? 100 : (recoveryMetrics.severity === 'warn' ? 70 : 0);
  const obsScore = scoreBucket(comps.observability);

  // weighted readiness (30% load, 30% security, 20% recovery, 20% observability)
  const overall = Math.round((loadScore*0.30)+(securityScore*0.30)+(recoveryScore*0.20)+(obsScore*0.20));

  function severityClass(severity) {
    return severity === 'pass' ? 'pass' : (severity === 'warn' ? 'warn' : 'fail');
  }

  function formatMs(ms) {
    if (ms < 1000) return ms + ' ms';
    return (ms / 1000).toFixed(1) + ' s';
  }

  let html = `<!doctype html><html><head><meta charset="utf-8"><title>Operational Readiness Report</title><style>body{font-family:Arial;margin:24px;background:#f5f5f5} .card{border:1px solid #ddd;padding:12px;margin:8px 0;border-radius:6px;background:white} h1{color:#333} h2{border-bottom:2px solid #ddd;padding-bottom:8px;color:#333} .pass{color:green;font-weight:bold} .fail{color:red;font-weight:bold} .warn{color:orange;font-weight:bold} .skipped{color:gray} .metrics-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px} .metric{padding:8px;background:#f9f9f9;border-left:4px solid #ddd;border-radius:3px} .metric.pass{border-left-color:green} .metric.warn{border-left-color:orange} .metric.fail{border-left-color:red} .metric-value{font-size:18px;font-weight:bold} .metric-label{font-size:12px;color:#666}</style></head><body><h1>Operational Readiness Report</h1>`;

  // Overall score
  html += `<div class="card"><h2>Overall Readiness Score: <span class="${severityClass(recoveryMetrics.severity)}">${overall}/100</span></h2><p>Load: ${loadScore} | Security: ${securityScore} | Recovery: ${recoveryScore} | Observability: ${obsScore}</p></div>`;

  // Recovery metrics detail
  html += `<div class="card"><h2>Recovery Objectives</h2><div class="metrics-grid">`;
  html += `<div class="metric ${recoveryMetrics.avg_rto_ms > 60000 ? 'fail' : 'pass'}"><div class="metric-value">${formatMs(recoveryMetrics.avg_rto_ms)}</div><div class="metric-label">Average RTO (target &lt; 60s)</div></div>`;
  html += `<div class="metric ${recoveryMetrics.p95_rto_ms > 90000 ? 'warn' : 'pass'}"><div class="metric-value">${formatMs(recoveryMetrics.p95_rto_ms)}</div><div class="metric-label">P95 RTO (target &lt; 90s)</div></div>`;
  html += `<div class="metric ${recoveryMetrics.avg_rpo_ms > 30000 ? 'fail' : 'pass'}"><div class="metric-value">${formatMs(recoveryMetrics.avg_rpo_ms)}</div><div class="metric-label">Average RPO (target &lt; 30s)</div></div>`;
  html += `<div class="metric ${recoveryMetrics.recovery_success_rate < 0.99 ? 'fail' : 'pass'}"><div class="metric-value">${(recoveryMetrics.recovery_success_rate*100).toFixed(1)}%</div><div class="metric-label">Success Rate (target &gt; 99%)</div></div>`;
  html += `<div class="metric"><div class="metric-value">${formatMs(recoveryMetrics.max_rto_ms)}</div><div class="metric-label">Worst-case RTO (informational)</div></div>`;
  html += `<div class="metric ${severityClass(recoveryMetrics.severity)}"><div class="metric-value">${recoveryMetrics.severity.toUpperCase()}</div><div class="metric-label">Recovery Status</div></div>`;
  html += `</div></div>`;

  // Test results
  html += '<div class="card"><h2>Test Results</h2><ul>';
  for(const s of summary){
    const status = s.status || 'unknown';
    html += `<li><strong>${s.test}</strong>: <span class="${status}">${status}</span>`;
    if(s.resultFile) html += ` - <a href="../results/${s.resultFile.split('/').pop()}">result</a>`;
    html += `</li>`;
  }
  html += '</ul></div>';

  // Detailed artifacts
  html += '<div class="card"><h2>Detailed Artifacts</h2><ul>';
  const resultsEntries = summary.map(s=>s.resultFile).filter(Boolean).map(p=>p.split('/').pop());
  for(const r of resultsEntries){ html += `<li><a href="../results/${r}">${r}</a></li>` }
  html += '</ul></div>';

  html += '</body></html>';
  fs.writeFileSync(outHtml, html);
}
