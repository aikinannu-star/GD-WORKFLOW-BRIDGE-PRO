const fs = require('fs');
const path = require('path');

async function run(){
  const backupsDir = path.join(__dirname,'..','..','backups');
  const dataFile = path.join(__dirname,'..','..','data','marketplace','remediation_events.json');
  if(!fs.existsSync(backupsDir)) return { test: 'restore-test', status: 'skipped', reason: 'no-backups' };
  const files = fs.readdirSync(backupsDir).filter(f=>f.includes('remediation_events_')).sort();
  if(files.length===0) return { test: 'restore-test', status: 'skipped', reason: 'no-backups' };
  const latest = path.join(backupsDir, files[files.length-1]);
  const restoreStarted = new Date().toISOString();
  fs.copyFileSync(latest, dataFile);
  const restoreCompleted = new Date().toISOString();
  const restoreDurationMs = Date.parse(restoreCompleted) - Date.parse(restoreStarted);
  return { test: 'restore-test', restoredFrom: latest, status: 'pass', restore_started_at: restoreStarted, restore_completed_at: restoreCompleted, restore_duration_ms: restoreDurationMs };
}
module.exports={run};
