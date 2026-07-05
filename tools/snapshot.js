#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const diff = require('diff');

function parseArgs(argv) {
  const opts = { _: [] };
  let pendingKey = null;
  argv.forEach(token => {
    if (token.startsWith('--')) {
      const [key, value] = token.slice(2).split('=');
      if (value !== undefined) {
        opts[key] = value;
      } else {
        pendingKey = key;
      }
    } else if (pendingKey) {
      opts[pendingKey] = token;
      pendingKey = null;
    } else {
      opts._.push(token);
    }
  });
  return opts;
}

function sanitizeBranch(name) {
  return name.replace(/[^a-zA-Z0-9_\-]+/g, '-').replace(/^-+|-+$/g, '') || 'unknown-branch';
}

function getGitBranch() {
  try {
    return execSync('git rev-parse --abbrev-ref HEAD', { encoding: 'utf8' }).trim();
  } catch {
    return null;
  }
}

function ensureDir(dir) {
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }
}

function escapeHtml(s) {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function sortKeys(obj) {
  if (Array.isArray(obj)) return obj.map(sortKeys);
  if (obj && typeof obj === 'object') {
    return Object.keys(obj).sort().reduce((out, key) => {
      out[key] = sortKeys(obj[key]);
      return out;
    }, {});
  }
  return obj;
}

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function prettyJson(obj) {
  return JSON.stringify(obj, null, 2);
}

function getDefaultBranch() {
  const envBranch = process.env.GITHUB_REF_NAME || process.env.GITHUB_HEAD_REF || process.env.BRANCH || null;
  if (envBranch) return envBranch;
  const githubRef = process.env.GITHUB_REF || null;
  if (githubRef) {
    const parts = githubRef.split('/');
    return parts.slice(2).join('/');
  }
  return getGitBranch() || 'unknown-branch';
}

function getRunId() {
  return process.env.GITHUB_RUN_ID || process.env.GITHUB_RUN_NUMBER || `${Date.now()}`;
}

function findLatestSnapshot(dir) {
  if (!fs.existsSync(dir)) return null;
  const files = fs.readdirSync(dir).filter(f => f.endsWith('.json') && f.includes('marketplace_snapshot_'));
  if (!files.length) return null;
  let latest = null;
  let latestM = 0;
  for (const file of files) {
    const filePath = path.join(dir, file);
    const mtime = fs.statSync(filePath).mtimeMs;
    if (mtime > latestM) {
      latestM = mtime;
      latest = filePath;
    }
  }
  return latest;
}

function diffToHtml(patch) {
  return patch
    .split('\n')
    .map(line => {
      const escaped = escapeHtml(line);
      if (line.startsWith('+++') || line.startsWith('---') || line.startsWith('***')) {
        return `<span style="color:#444; display:block;">${escaped}</span>`;
      }
      if (line.startsWith('+')) {
        return `<span style="background:#e6ffed; display:block;">${escaped}</span>`;
      }
      if (line.startsWith('-')) {
        return `<span style="background:#ffecec; display:block;">${escaped}</span>`;
      }
      if (line.startsWith('@@')) {
        return `<span style="background:#f0f4ff; display:block;">${escaped}</span>`;
      }
      return `<span style="display:block;">${escaped}</span>`;
    })
    .join('');
}

function writeReport(baselineFile, currentFile, summary, patch, outFile) {
  const html = `<!doctype html>
<html>
<head>
<meta charset="utf-8" />
<title>Snapshot Diff Report</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:24px;line-height:1.5;color:#111;}
pre{white-space:pre-wrap;word-break:break-word;background:#f7f7f7;padding:16px;border-radius:10px;overflow-x:auto;}
code{font-family:Menlo,Monaco,Consolas,'Courier New',monospace;}
.summary{margin-bottom:24px;padding:18px;border:1px solid #dbe4ee;border-radius:10px;background:#f8fbff;}
.summary h2{margin-top:0;}
.link-list{margin:16px 0;padding:0;list-style:none;}
.link-list li{margin:4px 0;}
.diffline{display:block;}
</style>
</head>
<body>
<h1>Snapshot Diff Report</h1>
<section class="summary">
  <h2>Summary</h2>
  <p><strong>Branch:</strong> ${escapeHtml(summary.branch)}</p>
  <p><strong>Run:</strong> ${escapeHtml(summary.runId)}</p>
  <p><strong>Baseline File:</strong> <code>${escapeHtml(path.basename(baselineFile))}</code></p>
  <p><strong>Current Snapshot:</strong> <code>${escapeHtml(path.basename(currentFile))}</code></p>
  <p><strong>Result:</strong> ${escapeHtml(summary.result)}</p>
  <p><strong>Changed sections:</strong> ${summary.changes}</p>
  <ul class="link-list">
    <li><a href="${escapeHtml(path.relative(path.dirname(outFile), baselineFile))}">Baseline JSON</a></li>
    <li><a href="${escapeHtml(path.relative(path.dirname(outFile), currentFile))}">Current JSON</a></li>
  </ul>
</section>
<section>
  <h2>Unified JSON Diff</h2>
  <pre>${diffToHtml(patch)}</pre>
</section>
</body>
</html>`;
  ensureDir(path.dirname(outFile));
  fs.writeFileSync(outFile, html, 'utf8');
}

function writeHistoryIndex(branch, baselinePath, historyDir, reportFile, indexFile) {
  const entries = fs.existsSync(historyDir)
    ? fs.readdirSync(historyDir).filter(f => f.endsWith('.json')).sort()
    : [];
  const rows = entries
    .map(runFile => {
      const runId = runFile.replace(/\.json$/, '');
      const snapshotRel = path.relative(path.dirname(indexFile), path.join(historyDir, runFile));
      const reportRel = runId === path.basename(reportFile, `-${runId}-diff.html`) ? path.relative(path.dirname(indexFile), reportFile) : path.relative(path.dirname(indexFile), reportFile);
      return `<tr><td>${escapeHtml(runId)}</td><td><a href="${escapeHtml(snapshotRel)}">snapshot</a></td><td>${fs.existsSync(reportFile) ? `<a href="${escapeHtml(reportRel)}">diff</a>` : 'n/a'}</td></tr>`;
    })
    .join('\n');
  const html = `<!doctype html>
<html>
<head>
<meta charset="utf-8" />
<title>Snapshot History Index</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:24px;color:#111;}
table{border-collapse:collapse;width:100%;}
th,td{border:1px solid #dbe4ee;padding:10px;text-align:left;}
th{background:#f0f4ff;}
tr:nth-child(even){background:#f9fbff;}
a{color:#0366d6;text-decoration:none;}
a:hover{text-decoration:underline;}
</style>
</head>
<body>
<h1>Snapshot History for ${escapeHtml(branch)}</h1>
<p>Baseline: <a href="${escapeHtml(path.relative(path.dirname(indexFile), baselinePath))}">${escapeHtml(path.basename(baselinePath))}</a></p>
<table>
<thead><tr><th>Run</th><th>Snapshot</th><th>Diff Report</th></tr></thead>
<tbody>
${rows}
</tbody>
</table>
</body>
</html>`;
  ensureDir(path.dirname(indexFile));
  fs.writeFileSync(indexFile, html, 'utf8');
}

function analyzeSnapshot(snapshot) {
  const files = snapshot.files || {};
  const summary = {
    pluginCount: (files['plugins.json'] || []).length,
    versionCount: (files['plugins_versions.json'] || []).length,
    installCount: (files['plugin_installs.json'] || []).length,
    keyCount: (files['plugin_keys.json'] || []).length,
    ratingCount: (files['plugin_ratings.json'] || []).length,
  };
  const problems = [];

  const plugins = files['plugins.json'] || [];
  const installs = files['plugin_installs.json'] || [];
  const keys = files['plugin_keys.json'] || [];
  const ratings = files['plugin_ratings.json'] || [];

  const ids = new Set();
  const slugTenant = new Set();
  plugins.forEach(plugin => {
    if (!plugin.id) {
      problems.push('Plugin missing id.');
    }
    if (ids.has(plugin.id)) {
      problems.push(`Duplicate plugin id: ${plugin.id}`);
    }
    ids.add(plugin.id);
    const key = `${plugin.slug || plugin.name || 'unknown'}::${plugin.tenant_id || ''}`;
    if (slugTenant.has(key)) {
      problems.push(`Duplicate slug/tenant combination: ${key}`);
    }
    slugTenant.add(key);
    if (!plugin.version) {
      problems.push(`Plugin ${plugin.id} missing version.`);
    }
  });

  const pluginIds = new Set(plugins.map(p => p.id));
  installs.forEach(install => {
    if (!pluginIds.has(install.plugin_id)) {
      problems.push(`Install references unknown plugin_id ${install.plugin_id}`);
    }
  });
  keys.forEach(k => {
    if (!pluginIds.has(k.plugin_id)) {
      problems.push(`Key references unknown plugin_id ${k.plugin_id}`);
    }
  });
  ratings.forEach(r => {
    if (!pluginIds.has(r.plugin_id)) {
      problems.push(`Rating references unknown plugin_id ${r.plugin_id}`);
    }
  });

  if (summary.pluginCount === 0) problems.push('No plugins found in snapshot.');
  if (summary.installCount > summary.pluginCount * 10) {
    problems.push('High install-to-plugin ratio; more than 10 installs per plugin on average.');
  }
  if (keys.filter(k => k.revoked).length > Math.max(10, summary.keyCount * 0.25)) {
    problems.push('More than 25% of keys are revoked.');
  }

  return { summary, problems };
}

function writeAnalysisReport(branch, runId, snapshotFile, result, outFile) {
  const html = `<!doctype html>
<html>
<head>
<meta charset="utf-8" />
<title>Snapshot Architecture Analysis</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:24px;color:#111;}
pre{white-space:pre-wrap;word-break:break-word;background:#f7f7f7;padding:16px;border-radius:10px;}
section{margin-bottom:24px;}
ul{margin:0; padding-left:20px;}
</style>
</head>
<body>
<h1>Snapshot Architecture Intelligence</h1>
<section>
  <h2>Snapshot</h2>
  <p>Branch: <strong>${escapeHtml(branch)}</strong></p>
  <p>Run: <strong>${escapeHtml(runId)}</strong></p>
  <p>Snapshot file: <code>${escapeHtml(path.basename(snapshotFile))}</code></p>
</section>
<section>
  <h2>Counts</h2>
  <pre>${escapeHtml(JSON.stringify(result.summary, null, 2))}</pre>
</section>
<section>
  <h2>Findings</h2>
  ${result.problems.length ? `<ul>${result.problems.map(p => `<li>${escapeHtml(p)}</li>`).join('')}</ul>` : '<p>No issues detected.</p>'}
</section>
</body>
</html>`;
  ensureDir(path.dirname(outFile));
  fs.writeFileSync(outFile, html, 'utf8');
}

function printHelp() {
  console.log(`Usage: node tools/snapshot.js <command> [options]

Commands:
  diff                    Compare latest snapshot against branch baseline and generate HTML diff.
  approve [runId]         Approve and promote a history snapshot as the branch baseline.
                          Requires approval to be requested via 'request-approval' first.
  request-approval        Flag latest diff as pending approval (called by CI when differences detected).
  list-approvals          Show pending and recent approvals for branch.
  history                 Write branch history index and list known runs.
  analyze                 Run architecture intelligence analysis on latest snapshot.
  help                    Show this help message.

Options:
  --branch <name>         Branch name to use
  --run-id <id>           Run identifier for history snapshots
  --data-dir <path>       Data directory containing marketplace snapshots
  --baseline-store <path> Baseline storage directory
  --history-store <path>  History storage directory
  --report-dir <path>     Output directory for HTML reports
  --approved-by <name>    Approver name (for audit trail)
  --reason <text>         Approval reason (optional)
  --skip-approval-check   Bypass approval requirement (override)
`);
}

function getApprovalRecordPath(reportDir, branch) {
  return path.join(reportDir, `${branch}.approval.json`);
}

function readApprovalRecord(approvalPath) {
  if (!fs.existsSync(approvalPath)) return null;
  return JSON.parse(fs.readFileSync(approvalPath, 'utf8'));
}

function writeApprovalRecord(approvalPath, record) {
  ensureDir(path.dirname(approvalPath));
  fs.writeFileSync(approvalPath, JSON.stringify(record, null, 2), 'utf8');
}

function recordPendingApproval(reportDir, branch, runId) {
  const approvalPath = getApprovalRecordPath(reportDir, branch);
  const record = {
    status: 'pending',
    branch,
    latestRunId: runId,
    requestedAt: new Date().toISOString(),
    approvedAt: null,
    approvedBy: null,
    reason: null,
  };
  writeApprovalRecord(approvalPath, record);
  return record;
}

function isApprovalPending(reportDir, branch) {
  const record = readApprovalRecord(getApprovalRecordPath(reportDir, branch));
  return record && record.status === 'pending';
}

function approveBaseline(reportDir, branch, approvedBy, reason) {
  const approvalPath = getApprovalRecordPath(reportDir, branch);
  const record = readApprovalRecord(approvalPath) || { status: 'pending', branch };
  record.status = 'approved';
  record.approvedAt = new Date().toISOString();
  record.approvedBy = approvedBy || 'unknown';
  record.reason = reason || '';
  writeApprovalRecord(approvalPath, record);
  return record;
}

function runDiff(opts) {
  const latestSnapshot = findLatestSnapshot(opts.dataDir);
  if (!latestSnapshot) {
    console.error('No snapshot found in', opts.dataDir);
    process.exit(2);
  }

  const baselinePath = path.join(opts.baselineStore, `${opts.branch}.json`);
  ensureDir(opts.baselineStore);
  ensureDir(opts.historyDir);
  ensureDir(opts.reportDir);

  const historyFile = path.join(opts.historyDir, `${opts.runId}.json`);
  fs.copyFileSync(latestSnapshot, historyFile);

  const historyIndexFile = path.join(opts.reportDir, `${opts.branch}-index.html`);
  const reportFile = path.join(opts.reportDir, `${opts.branch}-${opts.runId}-diff.html`);

  if (!fs.existsSync(baselinePath)) {
    fs.copyFileSync(latestSnapshot, baselinePath);
    writeHistoryIndex(opts.branch, baselinePath, opts.historyDir, reportFile, historyIndexFile);
    console.log('Baseline created for branch', opts.branch, 'at', baselinePath);
    console.log('No diff generated on first run. CI will pass.');
    process.exit(0);
  }

  const baselineData = sortKeys(readJson(baselinePath));
  const currentData = sortKeys(readJson(historyFile));
  const basePretty = prettyJson(baselineData);
  const currPretty = prettyJson(currentData);

  if (basePretty === currPretty) {
    writeHistoryIndex(opts.branch, baselinePath, opts.historyDir, '', historyIndexFile);
    console.log('No differences detected between baseline and current snapshot.');
    process.exit(0);
  }

  const patch = diff.createTwoFilesPatch(path.basename(baselinePath), path.basename(historyFile), basePretty, currPretty, '', '');
  const summary = {
    branch: opts.branch,
    runId: opts.runId,
    result: 'Differences detected',
    changes: patch.split('\n').filter(line => line.startsWith('+') || line.startsWith('-')).length,
  };
  writeReport(baselinePath, historyFile, summary, patch, reportFile);
  writeHistoryIndex(opts.branch, baselinePath, opts.historyDir, reportFile, historyIndexFile);
  
  // Record pending approval for this diff
  recordPendingApproval(opts.reportDir, opts.branch, opts.runId);
  console.error('Differences detected. Report written to', reportFile);
  console.error('Approval required. Run: node tools/snapshot.js approve --approved-by <name> [--reason <reason>]');
  process.exit(1);
}

function runApprove(opts, args) {
  const latestSnapshot = findLatestSnapshot(opts.dataDir);
  if (!latestSnapshot) {
    console.error('No snapshot found in', opts.dataDir);
    process.exit(2);
  }
  ensureDir(opts.baselineStore);
  ensureDir(opts.historyDir);
  
  // Check for pending approval
  const isApprovalRequired = isApprovalPending(opts.reportDir, opts.branch);
  if (isApprovalRequired && !args['skip-approval-check'] && !args['approved-by']) {
    console.error('Baseline promotion requires approval.');
    console.error('Use: node tools/snapshot.js approve --approved-by <name> [--reason <reason>]');
    process.exit(1);
  }
  
  const baselinePath = path.join(opts.baselineStore, `${opts.branch}.json`);
  let sourceSnapshot = latestSnapshot;
  if (opts.runId) {
    const candidate = path.join(opts.historyDir, `${opts.runId}.json`);
    if (!fs.existsSync(candidate)) {
      console.error('Requested history run does not exist:', opts.runId);
      process.exit(4);
    }
    sourceSnapshot = candidate;
  }
  fs.copyFileSync(sourceSnapshot, baselinePath);
  
  // Mark approval
  if (args['approved-by']) {
    approveBaseline(opts.reportDir, opts.branch, args['approved-by'], args.reason);
  }
  
  console.log('Approved baseline for branch', opts.branch, 'from', sourceSnapshot);
  console.log('Baseline stored at', baselinePath);
  console.log('Approval recorded by:', args['approved-by'] || 'manual-override');
}

function runRequestApproval(opts) {
  const approvalPath = getApprovalRecordPath(opts.reportDir, opts.branch);
  if (fs.existsSync(approvalPath)) {
    const record = readApprovalRecord(approvalPath);
    console.log('Approval already recorded for branch', opts.branch);
    console.log(JSON.stringify(record, null, 2));
    process.exit(0);
  }
  const record = recordPendingApproval(opts.reportDir, opts.branch, opts.runId);
  console.log('Approval requested for branch', opts.branch, 'run', opts.runId);
  console.log(JSON.stringify(record, null, 2));
  process.exit(0);
}

function runListApprovals(opts) {
  const approvalPath = getApprovalRecordPath(opts.reportDir, opts.branch);
  if (!fs.existsSync(approvalPath)) {
    console.log('No approval record found for branch', opts.branch);
    process.exit(0);
  }
  const record = readApprovalRecord(approvalPath);
  console.log('Approval Status for', opts.branch, ':');
  console.log(JSON.stringify(record, null, 2));
}

function runHistory(opts) {
  ensureDir(opts.historyDir);
  const historyIndexFile = path.join(opts.reportDir, `${opts.branch}-index.html`);
  const baselinePath = path.join(opts.baselineStore, `${opts.branch}.json`);
  writeHistoryIndex(opts.branch, baselinePath, opts.historyDir, '', historyIndexFile);
  const entries = fs.readdirSync(opts.historyDir).filter(f => f.endsWith('.json')).sort();
  console.log(`Branch: ${opts.branch}`);
  console.log(`Baseline: ${fs.existsSync(baselinePath) ? baselinePath : '(none)'}`);
  console.log('Run history:');
  entries.forEach(entry => console.log('  -', entry.replace(/\.json$/, '')));
  console.log('History index written to', historyIndexFile);
}

function runAnalyze(opts) {
  const latestSnapshot = findLatestSnapshot(opts.dataDir);
  if (!latestSnapshot) {
    console.error('No snapshot found in', opts.dataDir);
    process.exit(2);
  }
  ensureDir(opts.historyDir);
  ensureDir(opts.reportDir);
  const historyFile = path.join(opts.historyDir, `${opts.runId}.json`);
  fs.copyFileSync(latestSnapshot, historyFile);
  const snapshot = readJson(historyFile);
  const result = analyzeSnapshot(snapshot);
  const analysisFile = path.join(opts.reportDir, `${opts.branch}-${opts.runId}-analysis.html`);
  writeAnalysisReport(opts.branch, opts.runId, historyFile, result, analysisFile);
  console.log('Architecture intelligence report written to', analysisFile);
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  const command = args._[0] ? args._[0].toLowerCase() : 'diff';
  const dataDir = path.resolve(args['data-dir'] || process.env.GDWB_DATA_BASE || path.resolve(__dirname, '../services/data'));
  const baselineStore = path.resolve(args['baseline-store'] || path.resolve(__dirname, 'snapshot-baselines'));
  const historyStore = path.resolve(args['history-store'] || path.resolve(__dirname, 'snapshot-history'));
  const reportDir = path.resolve(args['report-dir'] || path.resolve(__dirname, 'snapshot-diffs'));
  const branch = sanitizeBranch(args.branch || getDefaultBranch());
  const runId = args['run-id'] || getRunId();
  const opts = { dataDir, baselineStore, historyDir: path.join(historyStore, branch), reportDir, branch, runId };
  switch (command) {
    case 'diff':
      runDiff(opts);
      break;
    case 'approve':
    case 'approve-baseline':
      runApprove(opts, args);
      break;
    case 'request-approval':
      runRequestApproval(opts);
      break;
    case 'list-approvals':
      runListApprovals(opts);
      break;
    case 'history':
      runHistory(opts);
      break;
    case 'analyze':
      runAnalyze(opts);
      break;
    case 'help':
      printHelp();
      process.exit(0);
    default:
      console.error('Unknown command:', command);
      printHelp();
      process.exit(1);
  }
}

main();
