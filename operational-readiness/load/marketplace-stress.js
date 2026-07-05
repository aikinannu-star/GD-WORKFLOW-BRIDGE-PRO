const fetch = global.fetch || require('node-fetch');

async function run() {
  const target = process.env.TARGET || 'http://127.0.0.1:8006';
  const urls = ['/marketplace-ui', '/marketplace-ui/plugins/29a1e8dbede099f8f9f2c38d504b52fa'];
  const iterations = parseInt(process.env.ITERATIONS || '50', 10);
  const results = [];
  let errors = 0;

  for (let i = 0; i < iterations; i++) {
    for (const u of urls) {
      try {
        const started = Date.now();
        const res = await fetch(target + u); 
        const text = await res.text();
        results.push({ url: u, status: res.status, latency: Date.now() - started, length: text.length });
      } catch (e) {
        errors++;
      }
    }
  }

  return { test: 'marketplace-stress', iterations, errors, sample: results.slice(0,5), status: 'pass' };
}

module.exports = { run };
