# Operational Readiness Suite

Run a battery of operational hardening checks locally or in CI.

Usage:

```bash
# Run the whole readiness suite
npm run operational:readiness

# Or run a single script
node operational-readiness/load/tenant-load-test.js
```

Scripts are intentionally conservative: they probe the local server at `http://127.0.0.1:8006` by default and will skip tests that rely on endpoints not present in the environment.

Outputs: `operational-readiness/results/*.json` and a single HTML report at `operational-readiness/report/operational-readiness.html`.
