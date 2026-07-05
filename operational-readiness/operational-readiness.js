const fs = require('fs');
const path = require('path');

const resultsDir = path.join(__dirname, 'results');
if (!fs.existsSync(resultsDir)) fs.mkdirSync(resultsDir);

const tests = [
  './load/tenant-load-test.js',
  './load/marketplace-stress.js',
  './load/analytics-benchmark.js',
  './load/load-profile-ramp.js',
  './load/load-profile-burst.js',
  './load/load-profile-endurance.js',
  './load/load-profile-mixed.js',
  './security/tenant-isolation.test.js',
  './security/auth-matrix.test.js',
  './recovery/backup-test.js',
  './recovery/restore-test.js',
  './observability/metrics-check.js',
  './observability/health-endpoints.js'
];

(async () => {
  const summary = [];
  for (const t of tests) {
    try {
      const modPath = path.join(__dirname, t);
      if (!fs.existsSync(modPath)) {
        console.log('SKIP (missing):', t);
        summary.push({ test: t, status: 'skipped', reason: 'missing' });
        continue;
      }
      // clear require cache to allow re-run within same process
      delete require.cache[require.resolve(modPath)];
      const mod = require(modPath);
      if (typeof mod.run !== 'function') {
        console.log('SKIP (no run):', t);
        summary.push({ test: t, status: 'skipped', reason: 'no-run' });
        continue;
      }
      console.log('RUN:', t);
      const start = new Date().toISOString();
      const res = await mod.run();
      const end = new Date().toISOString();
      const outFile = path.join(resultsDir, path.basename(t).replace(/\.[^.]+$/, '') + '.json');
      const out = Object.assign({}, res, { started_at: start, completed_at: end });
      fs.writeFileSync(outFile, JSON.stringify(out, null, 2));
      summary.push({ test: t, status: out.status || 'pass', resultFile: outFile });
    } catch (err) {
      console.error('ERROR running', t, err.message);
      summary.push({ test: t, status: 'error', error: err.message });
    }
  }

  const summaryFile = path.join(resultsDir, 'summary.json');
  fs.writeFileSync(summaryFile, JSON.stringify(summary, null, 2));
  // generate report if generator exists
  const gen = path.join(__dirname, 'report', 'generate-report.js');
  if (fs.existsSync(gen)) {
    require(gen)(summaryFile, path.join(__dirname, 'report', 'operational-readiness.html'));
    console.log('Report generated at report/operational-readiness.html');
  } else {
    console.log('No report generator found. Summary written to', summaryFile);
  }
})();
