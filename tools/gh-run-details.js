#!/usr/bin/env node
// Usage: node tools/gh-run-details.js <run_id>
const runId = process.argv[2];
if (!runId) { console.error('Usage: node tools/gh-run-details.js <run_id>'); process.exit(2); }
const owner = 'aikinannu-star';
const repo = 'GD-WORKFLOW-BRIDGE-PRO';
const fetchFn = global.fetch || (async (u, o) => { const nodeFetch = require('node-fetch'); return nodeFetch(u, o); });

(async () => {
  try {
    const url = `https://api.github.com/repos/${owner}/${repo}/actions/runs/${runId}/jobs`;
    const res = await fetchFn(url, { headers: { 'Accept': 'application/vnd.github+json', 'User-Agent': 'gh-run-details' } });
    const data = await res.json();
    const jobs = data.jobs || [];
    if (jobs.length === 0) { console.log('No jobs found for run', runId); process.exit(0); }
    for (const job of jobs) {
      console.log('---');
      console.log(`Job: ${job.name} (id:${job.id}) status:${job.status} conclusion:${job.conclusion} url:${job.html_url}`);
      if (Array.isArray(job.steps)) {
        for (const step of job.steps) {
          if (step.conclusion && step.conclusion !== 'success') {
            console.error(` Step FAILED: ${step.name} -> conclusion:${step.conclusion}`);
          } else {
            console.log(` Step: ${step.name} -> conclusion:${step.conclusion || step.status}`);
          }
        }
      }
      if (job.conclusion && job.conclusion !== 'success') {
        console.error(` Job failed: ${job.name} -> logs: ${job.logs_url}`);
      }
    }
  } catch (e) {
    console.error('Error fetching run jobs:', e && e.message ? e.message : e);
    process.exit(2);
  }
})();
