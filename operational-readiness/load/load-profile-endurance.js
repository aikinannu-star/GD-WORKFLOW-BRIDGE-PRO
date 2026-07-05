const fetch = global.fetch || require('node-fetch');

/**
 * Extended Load Profile: Endurance
 * Sustain a moderate load over a longer period to check for memory leaks, cache issues, etc.
 */

async function run() {
  const target = process.env.TARGET || 'http://127.0.0.1:8006';
  const path = '/api/v1/intelligence-learning';
  const concurrency = 10;
  const duration = 60; // 60 seconds of sustained load

  const latencies = [];
  let errors = 0;
  const enduranceStart = Date.now();

  const workers = Array.from({ length: concurrency }, () =>
    (async () => {
      while (Date.now() - enduranceStart < duration * 1000) {
        try {
          const started = Date.now();
          const res = await fetch(target + path, { timeout: 10000 });
          await res.text();
          latencies.push(Date.now() - started);
        } catch (e) {
          errors++;
        }
      }
    })()
  );

  await Promise.all(workers);
  const enduranceDurationMs = Date.now() - enduranceStart;

  latencies.sort((a, b) => a - b);
  const avgLatency = latencies.length > 0 ? Math.round(latencies.reduce((a, b) => a + b) / latencies.length) : 0;

  return {
    test: 'load-profile-endurance',
    target,
    concurrency,
    endurance_duration_ms: enduranceDurationMs,
    total_requests: latencies.length + errors,
    errors,
    avg_latency: avgLatency,
    p50: latencies[Math.floor(latencies.length * 0.5)] || 0,
    p95: latencies[Math.floor(latencies.length * 0.95)] || 0,
    p99: latencies[Math.floor(latencies.length * 0.99)] || 0,
    max_latency: latencies[latencies.length - 1] || 0,
    status: errors < (latencies.length + errors) * 0.05 ? 'pass' : 'warn'
  };
}

module.exports = { run };
