const fetch = global.fetch || require('node-fetch');

/**
 * Extended Load Profile: Burst
 * Send a large spike of concurrent requests to assess system responsiveness
 */

async function run() {
  const target = process.env.TARGET || 'http://127.0.0.1:8006';
  const path = '/api/v1/intelligence-learning';
  const concurrency = 100;
  const requestsPerWorker = 10;

  const latencies = [];
  let errors = 0;
  const burstStart = Date.now();

  const workers = Array.from({ length: concurrency }, () =>
    (async () => {
      for (let i = 0; i < requestsPerWorker; i++) {
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
  const burstDurationMs = Date.now() - burstStart;

  latencies.sort((a, b) => a - b);
  const throughput = (latencies.length + errors) / (burstDurationMs / 1000);

  return {
    test: 'load-profile-burst',
    target,
    concurrency,
    burst_duration_ms: burstDurationMs,
    total_requests: latencies.length + errors,
    errors,
    throughput: Math.round(throughput),
    p50: latencies[Math.floor(latencies.length * 0.5)] || 0,
    p95: latencies[Math.floor(latencies.length * 0.95)] || 0,
    p99: latencies[Math.floor(latencies.length * 0.99)] || 0,
    status: errors < (latencies.length + errors) * 0.10 ? 'pass' : 'warn'
  };
}

module.exports = { run };
