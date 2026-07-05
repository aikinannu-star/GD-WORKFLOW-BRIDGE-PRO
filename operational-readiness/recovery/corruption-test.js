const fs = require('fs');
const path = require('path');

async function run(){
  const dataFile = path.join(__dirname,'..','..','data','marketplace','remediation_events.json');
  if(!fs.existsSync(dataFile)) return { test: 'corruption-test', status: 'skipped', reason: 'no-data' };
  const content = fs.readFileSync(dataFile,'utf8');
  try{ JSON.parse(content); return { test:'corruption-test', valid:true, status:'pass' }; }
  catch(e){ return { test:'corruption-test', valid:false, error:e.message, status:'fail' } }
}
module.exports={run};
