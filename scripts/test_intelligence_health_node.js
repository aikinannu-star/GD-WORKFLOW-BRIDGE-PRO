const http = require('http');

function fetchJson(path) {
  return new Promise((resolve, reject) => {
    const opts = { hostname: '127.0.0.1', port: 8006, path, method: 'GET' };
    const req = http.request(opts, (res) => {
      let body = '';
      res.on('data', (d) => body += d);
      res.on('end', () => {
        try {
          const j = JSON.parse(body);
          resolve({ status: res.statusCode, body: j });
        } catch (e) { reject(e); }
      });
    });
    req.on('error', reject);
    req.end();
  });
}

(async () => {
  try {
    const r = await fetchJson('/api/v1/intelligence-health');
    if (r.status !== 200) {
      console.error('Non-200 status', r.status);
      process.exit(2);
    }
    const d = r.body;
    const required = ['trend_confidence','stable_tenants_pct','anomaly_density','remediation_success_rate'];
    for (const k of required) {
      if (!(k in d)) {
        console.error('Missing key', k);
        process.exit(3);
      }
    }
    console.log('intelligence-health OK', JSON.stringify(d, null, 2));
    process.exit(0);
  } catch (e) {
    console.error('Error fetching intelligence-health', e);
    process.exit(1);
  }
})();
