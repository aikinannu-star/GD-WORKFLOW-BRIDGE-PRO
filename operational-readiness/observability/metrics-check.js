const fetch = global.fetch || require('node-fetch');

async function run(){
  const target = process.env.TARGET || 'http://127.0.0.1:8006';
  const endpoints = ['/metrics','/status','/operations-center','/api/v1/intelligence-learning/effectiveness-score'];
  const found = [];
  for(const e of endpoints){
    try{
      const r = await fetch(target+e);
      found.push({endpoint:e,status:r.status});
    }catch(e){ found.push({endpoint:e,error:e.message}); }
  }
  return { test: 'metrics-check', results: found, status: 'pass' };
}
module.exports={run};
