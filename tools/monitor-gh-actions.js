#!/usr/bin/env node
// Poll GitHub Actions for workflow runs matching a branch and commit
// Usage: node tools/monitor-gh-actions.js <owner> <repo> <branch> <commit> [interval_ms] [maxAttempts]

const owner = process.argv[2] || 'aikinannu-star';
const repo = process.argv[3] || 'GD-WORKFLOW-BRIDGE-PRO';
const branch = process.argv[4] || 'ci/marketplace-ts-tests';
const commit = process.argv[5] || null;
const interval = parseInt(process.argv[6] || '15000', 10);
const maxAttempts = parseInt(process.argv[7] || '40', 10);

const fetchFn = global.fetch || (async (u, o) => {
  const nodeFetch = require('node-fetch');
  return nodeFetch(u, o);
});

function sleep(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }

(async () => {
  console.log(`Monitoring GitHub Actions for ${owner}/${repo} branch=${branch} commit=${commit || '<any>'}`);
  for (let i = 1; i <= maxAttempts; i++) {
    console.log(`Attempt ${i}/${maxAttempts} — checking runs...`);
    try {
      const url = `https://api.github.com/repos/${owner}/${repo}/actions/runs?branch=${encodeURIComponent(branch)}`;
      const res = await fetchFn(url, { headers: { 'Accept': 'application/vnd.github+json', 'User-Agent': 'gh-actions-monitor' } });
      const data = await res.json();
      const runs = Array.isArray(data.workflow_runs) ? data.workflow_runs : [];
      let filtered = runs;
      if (commit) filtered = runs.filter(r => r.head_sha === commit);

      if (!filtered || filtered.length === 0) {
        console.log('No matching workflow runs found yet.');
        await sleep(interval);
        continue;
      }

      const allCompleted = filtered.every(r => r.status === 'completed');
      const allSuccess = filtered.every(r => r.conclusion === 'success');

      console.log('Found runs:');
      filtered.forEach(r => console.log(`${r.name} | id:${r.id} | status:${r.status} | conclusion:${r.conclusion} | url:${r.html_url}`));

      if (allCompleted) {
        if (allSuccess) {
          console.log('All runs for commit are completed and successful.');
          process.exit(0);
        } else {
          console.error('All runs completed but some conclusions are non-success.');
          filtered.filter(r => r.conclusion !== 'success').forEach(r => console.error(`FAILED: ${r.name} -> ${r.html_url} (conclusion=${r.conclusion})`));
          process.exit(1);
        }
      }
    } catch (e) {
      console.error('Error querying GitHub Actions API:', e && e.message ? e.message : e);
    }
    await sleep(interval);
  }
  console.error('Timed out waiting for workflow runs to complete.');
  process.exit(2);
})();
