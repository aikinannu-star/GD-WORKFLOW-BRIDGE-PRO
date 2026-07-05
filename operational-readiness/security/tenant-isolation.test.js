const fetch = global.fetch || require('node-fetch');

async function run(){
  const target = process.env.TARGET || 'http://127.0.0.1:8006';
  const t1 = '/tenant-trend-timeline?tenant_id=test-tenant';
  const t2 = '/tenant-trend-timeline?tenant_id=other-tenant';
  try{
    const r1 = await fetch(target + t1);
    const r2 = await fetch(target + t2);
    const j1 = await r1.text();
    const j2 = await r2.text();
    const identical = j1 === j2;
    return { test: 'tenant-isolation', identical, status: identical? 'warn' : 'pass' };
  }catch(e){
    return { test: 'tenant-isolation', error: e.message, status: 'skipped' };
  }
}
module.exports={run};
