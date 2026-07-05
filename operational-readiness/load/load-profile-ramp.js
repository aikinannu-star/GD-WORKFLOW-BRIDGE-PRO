const fetch = global.fetch || require('node-fetch');

/**
 * Extended Load Profile: Ramp-up
 * Gradually increase load over time to assess system behavior under increasing pressure
 */

async function run() {
  const target = process.env.TARGET || 'http://127.0.0.1:8006';
  const path = '/api/v1/intelligence-learning';
  const phases = [
    { concurrency: 5, duration: 10, name: '5 concurrent' },
    { concurrency: 10, duration: 10, name: '10 concurrent' },
    { concurrency: 20, duration: 10, name: '20 concurrent' },
    { concurrency: 40, duration: 10, name: '40 concurrent' }
  ];

  const phases_results = [];
  let errors = 0;
  let latencies = [];

  for (const phase of phases) {
    const phaseLatencies = [];
    const phaseStart = Date.now();
    const workers = [];

    for (let i = 0; i < phase.concurrency; i++) {
      workers.push((async () => {
        while (Date.now() - phaseStart < phase.duration * 1000) {
          try {
            const started = Date.now();
            const res = await fetch(target + path, { timeout: 10000 });
            await res.text();
            const latency = Date.now() - started;
            phaseLatencies.push(latency);
            latencies.push(latency);
          } catch (e) {
            errors++;
          }
        }
      })());
    }

    await Promise.all(workers);

    phaseLatencies.sort((a, b) => a - b);
    const p50 = phaseLatencies[Math.floor(phaseLatencies.length * 0.5)] || 0;
    const p95 = phaseLatencies[Math.floor(phaseLatencies.length * 0.95)] || 0;
    const p99 = phaseLatencies[Math.floor(phaseLatencies.length * 0.99)] || 0;

    phases_results.push({
      concurrency: phase.concurrency,
      name: phase.name,
      requests: phaseLatencies.length,
      p50, p95, p99
    });
  }

  latencies.sort((a, b) => a - b);
  return {
    test: 'load-profile-ramp',
    target,
    total_requests: latencies.length + errors,
    errors,
    p50: latencies[Math.floor(latencies.length * 0.5)] || 0,
    p95: latencies[Math.floor(latencies.length * 0.95)] || 0,
    p99: latencies[Math.floor(latencies.length * 0.99)] || 0,
    phases: phases_results,
    status: errors < (latencies.length + errors) * 0.05 ? 'pass' : 'warn'
  };
}

module.exports = { run };
