const fs = require('fs');
const path = require('path');

async function run(){
  const dataFile = path.join(__dirname,'..','..','data','marketplace','remediation_events.json');
  const backupsDir = path.join(__dirname,'..','..','backups');
  if(!fs.existsSync(dataFile)) return { test: 'backup-test', status: 'skipped', reason: 'no-data-file' };
  if(!fs.existsSync(backupsDir)) fs.mkdirSync(backupsDir);
  const started = new Date().toISOString();
  const ts = Date.now();
  const dest = path.join(backupsDir, `remediation_events_${ts}.json`);
  fs.copyFileSync(dataFile, dest);
  const ended = new Date().toISOString();
  const durationMs = Date.parse(ended) - Date.parse(started);
  const a = fs.readFileSync(dataFile,'utf8');
  const b = fs.readFileSync(dest,'utf8');
  const ok = a===b;
  return {
    test: 'backup-test',
    backupFile: dest,
    matched: ok,
    status: ok? 'pass':'fail',
    backup_started_at: started,
    backup_completed_at: ended,
    backup_duration_ms: durationMs
  };
}
module.exports={run};
