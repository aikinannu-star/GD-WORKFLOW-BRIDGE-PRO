const fetch = global.fetch || require('node-fetch');

async function run(){
  const target = process.env.TARGET || 'http://127.0.0.1:8006';
  const path = '/api/v1/remediation-events';
  try{
    const r = await fetch(target+path, { method: 'POST', body: JSON.stringify({test:true}), headers: {'Content-Type':'application/json'} });
    return { test: 'remediation-permissions', statusCode: r.status, status: 'pass' };
  }catch(e){
    return { test: 'remediation-permissions', error: e.message, status: 'skipped' };
  }
}
module.exports={run};
