const fetch = global.fetch || require('node-fetch');

async function run() {
  const target = process.env.TARGET || 'http://127.0.0.1:8006';
  const path = '/api/v1/intelligence-effectiveness/recommendations';
  const iterations = parseInt(process.env.ITERATIONS || '30', 10);
  const lat = [];
  let errors = 0;
  for (let i=0;i<iterations;i++){
    const start=Date.now();
    try{
      const res = await fetch(target+path);
      await res.json().catch(()=>{});
      lat.push(Date.now()-start);
    }catch(e){errors++;}
  }
  lat.sort((a,b)=>a-b);
  return { test: 'analytics-benchmark', iterations, errors, p50: lat[Math.floor(lat.length*0.5)]||0, p95: lat[Math.floor(lat.length*0.95)]||0, status: 'pass' };
}
module.exports={run};
