const fetch = global.fetch || require('node-fetch');

/**
 * Extended Load Profile: Mixed Workload
 * Combine different API endpoints to simulate realistic operator usage
 */

async function run() {
  const target = process.env.TARGET || 'http://127.0.0.1:8006';
  const endpoints = [
    '/api/v1/intelligence-learning/performance',
    '/api/v1/intelligence-learning/adoption-gaps',
    '/api/v1/intelligence-learning/recurring-issues',
    '/api/v1/intelligence-learning/trends',
    '/api/v1/intelligence-learning/effectiveness-score',
    '/operations-center',
    '/marketplace-ui/plugins/29a1e8dbede099f8f9f2c38d504b52fa'
  ];

  const concurrency = 15;
  const requestsPerWorker = 20;

  const latencies = [];
  const endpointStats = {};
  let errors = 0;

  // Initialize stats for each endpoint
  for (const e of endpoints) {
    endpointStats[e] = { latencies: [], errors: 0 };
  }

  const workers = Array.from({ length: concurrency }, () =>
    (async () => {
      for (let i = 0; i < requestsPerWorker; i++) {
        const endpoint = endpoints[i % endpoints.length];
        try {
          const started = Date.now();
          const res = await fetch(target + endpoint, { timeout: 10000 });
          await res.text();
          const latency = Date.now() - started;
          latencies.push(latency);
          endpointStats[endpoint].latencies.push(latency);
        } catch (e) {
          errors++;
          endpointStats[endpoint].errors++;
        }
      }
    })()
  );

  await Promise.all(workers);

  latencies.sort((a, b) => a - b);

  // Compute per-endpoint summaries
  const endpointSummaries = {};
  for (const endpoint in endpointStats) {
    const lats = endpointStats[endpoint].latencies;
    lats.sort((a, b) => a - b);
    endpointSummaries[endpoint] = {
      requests: lats.length,
      errors: endpointStats[endpoint].errors,
      p50: lats[Math.floor(lats.length * 0.5)] || 0,
      p95: lats[Math.floor(lats.length * 0.95)] || 0,
      p99: lats[Math.floor(lats.length * 0.99)] || 0
    };
  }

  return {
    test: 'load-profile-mixed',
    target,
    concurrency,
    total_requests: latencies.length + errors,
    errors,
    endpoints_tested: endpoints.length,
    p50: latencies[Math.floor(latencies.length * 0.5)] || 0,
    p95: latencies[Math.floor(latencies.length * 0.95)] || 0,
    p99: latencies[Math.floor(latencies.length * 0.99)] || 0,
    endpoint_summaries: endpointSummaries,
    status: errors < (latencies.length + errors) * 0.05 ? 'pass' : 'warn'
  };
}

module.exports = { run };
