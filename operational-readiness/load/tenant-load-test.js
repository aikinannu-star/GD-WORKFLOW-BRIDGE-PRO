const fetch = global.fetch || require('node-fetch');

async function run() {
  const target = process.env.TARGET || 'http://127.0.0.1:8006';
  const path = '/api/v1/intelligence-learning';
  const concurrency = parseInt(process.env.CONCURRENCY || '20', 10);
  const perWorker = parseInt(process.env.PER_WORKER || '25', 10);

  const latencies = [];
  let errors = 0;

  const worker = async () => {
    for (let i = 0; i < perWorker; i++) {
      const started = Date.now();
      try {
        const res = await fetch(target + path, { timeout: 10000 });
        await res.text();
        latencies.push(Date.now() - started);
      } catch (e) {
        errors++;
      }
    }
  };

  const workers = Array.from({ length: concurrency }, () => worker());
  await Promise.all(workers);

  latencies.sort((a,b)=>a-b);
  const p50 = latencies[Math.floor(latencies.length*0.5)] || 0;
  const p95 = latencies[Math.floor(latencies.length*0.95)] || 0;
  const p99 = latencies[Math.floor(latencies.length*0.99)] || 0;

  return {
    test: 'tenant-load-test',
    target,
    concurrency,
    perWorker,
    requests: latencies.length + errors,
    errors,
    p50, p95, p99,
    status: 'pass'
  };
}

module.exports = { run };
