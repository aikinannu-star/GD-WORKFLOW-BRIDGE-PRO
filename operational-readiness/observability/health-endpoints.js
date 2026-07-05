const fetch = global.fetch || require('node-fetch');

async function run(){
  const target = process.env.TARGET || 'http://127.0.0.1:8006';
  const endpoints=['/health','/status','/operations-center'];
  const res=[];
  for(const e of endpoints){
    try{const r=await fetch(target+e); res.push({endpoint:e,status:r.status});}catch(e){res.push({endpoint:e,error:e.message});}
  }
  return { test: 'health-endpoints', results: res, status: 'pass' };
}
module.exports={run};
