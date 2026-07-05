const { execSync } = require('child_process');

function runHarness() {
  console.log('Running synthetic harness...');
  const out = execSync('php scripts/synthetic_harness.php', { encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 });
  return out;
}

function extractJsonBlocks(stdout) {
  const blocks = [];
  // Match top-level JSON objects (naive but works for harness output)
  const re = /\{[\s\S]*?\n\}/g;
  let m;
  while ((m = re.exec(stdout)) !== null) {
    try {
      const parsed = JSON.parse(m[0]);
      blocks.push(parsed);
    } catch (e) {
      // ignore parse errors
    }
  }
  return blocks;
}

function assert(cond, msg) {
  if (!cond) {
    console.error('ASSERTION FAILED:', msg);
    process.exitCode = 2;
    throw new Error(msg);
  }
}

(async () => {
  try {
    const out = runHarness();
    console.log('Harness output length:', out.length);
    const blocks = extractJsonBlocks(out);
    console.log('Found JSON blocks:', blocks.length);
    assert(blocks.length >= 3, 'Expected at least 3 JSON outputs from harness');

    const [healthy, mixed, degrading] = blocks.slice(0, 3);

    // Basic schema checks
    for (const b of [healthy, mixed, degrading]) {
      assert(typeof b.metric === 'string', 'metric missing');
      assert(typeof b.fleet_average === 'number', 'fleet_average missing');
      assert(typeof b.fleet_stddev === 'number', 'fleet_stddev missing');
      assert(typeof b.tenant_count === 'number', 'tenant_count missing');
      assert(Array.isArray(b.tenants), 'tenants array missing');
    }

    console.log('Healthy anomalous_count:', healthy.anomalous_count);
    console.log('Mixed anomalous_count:', mixed.anomalous_count);
    console.log('Degrading anomalous_count:', degrading.anomalous_count);

    // Expectations (based on harness design)
    assert(healthy.anomalous_count === 1, 'Healthy scenario should flag 1 anomalous tenant');
    assert(mixed.anomalous_count >= 1, 'Mixed scenario should flag at least 1 anomalous tenant');
    assert(typeof degrading.anomalous_count === 'number', 'Degrading anomalous_count missing');

    console.log('Synthetic KPI validation passed');
    process.exit(0);
  } catch (e) {
    console.error('Validation failed:', e.message || e);
    process.exit(process.exitCode || 1);
  }
})();
