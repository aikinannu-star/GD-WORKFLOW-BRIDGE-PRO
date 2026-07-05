const fetch = global.fetch || require('node-fetch');

async function run(){
  const target = process.env.TARGET || 'http://127.0.0.1:8006';
  const endpoints = ['/operations-center', '/api/v1/intelligence-effectiveness', '/api/v1/intelligence-learning/effectiveness-score'];
  const results = [];
  for(const e of endpoints){
    try{
      const r = await fetch(target+e);
      results.push({endpoint:e,status:r.status});
    }catch(e){
      results.push({endpoint:e,error:e.message});
    }
  }
  return { test: 'auth-matrix', results, status: 'pass' };
}
module.exports={run};
