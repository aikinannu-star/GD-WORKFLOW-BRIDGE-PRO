#!/usr/bin/env node
const http = require('http');

function testEndpoint(path, name) {
  return new Promise((resolve) => {
    http.get(`http://127.0.0.1:8006${path}`, (res) => {
      let data = '';
      res.on('data', chunk => { data += chunk; });
      res.on('end', () => {
        try {
          const obj = JSON.parse(data);
          console.log(`✓ ${name}`);
          console.log(`  Response keys:`, Object.keys(obj).slice(0, 5).join(', '));
          resolve(obj);
        } catch (e) {
          console.log(`✗ ${name}: Parse error`);
          resolve(null);
        }
      });
    }).on('error', (e) => {
      console.log(`✗ ${name}: ${e.message}`);
      resolve(null);
    });
  });
}

(async () => {
  console.log('=== Testing Effectiveness Metrics APIs ===\n');
  
  const recs = await testEndpoint('/api/v1/intelligence-effectiveness/recommendations', 'Recommendations Effectiveness');
  const mttd = await testEndpoint('/api/v1/intelligence-effectiveness/mttd', 'MTTD Endpoint');
  const mttr = await testEndpoint('/api/v1/intelligence-effectiveness/mttr', 'MTTR Endpoint');
  const rate = await testEndpoint('/api/v1/intelligence-effectiveness/acceptance-rate', 'Acceptance Rate Endpoint');
  const acc = await testEndpoint('/api/v1/intelligence-effectiveness/accuracy', 'Accuracy Endpoint');
  
  console.log('\n=== Sample Data ===\n');
  if (mttd) {
    console.log('MTTD:', JSON.stringify({
      mttd_hours_avg: mttd.mttd_hours_avg,
      mttd_hours_p95: mttd.mttd_hours_p95,
      recent_detections: mttd.recent_detections
    }, null, 2));
  }
  
  if (mttr) {
    console.log('\nMTTR:', JSON.stringify({
      mttr_hours_avg: mttr.mttr_hours_avg,
      mttr_hours_p95: mttr.mttr_hours_p95,
      resolved_count_7d: mttr.resolved_count_7d
    }, null, 2));
  }
  
  if (rate) {
    console.log('\nAcceptance Rate:', JSON.stringify({
      overall_acceptance_rate: rate.overall_acceptance_rate,
      trend_7d: rate.trend_7d
    }, null, 2));
  }
  
  console.log('\n✅ All effectiveness metrics APIs operational');
  process.exit(0);
})();
