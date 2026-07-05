# Snapshot Approval Workflow

## Overview
The snapshot CLI implements approval-gated baseline promotion to prevent unintended marketplace state changes. When differences are detected between the current test run and the baseline, they must be explicitly approved before the baseline is updated.

## Workflow

### 1. Development & Testing
Run marketplace tests locally:
```bash
npm run test:ui
```

### 2. Automatic Diff Detection (CI)
When you push changes:
- GitHub Actions runs marketplace tests
- Snapshot diff engine compares output to branch baseline
- If differences detected → CI fails with approval required

### 3. Approval Commands

**List pending approvals:**
```bash
npm run snapshot:list-approvals -- --branch main
```

**Review the diff report:**
- Open `tools/snapshot-diffs/<branch>-<runId>-diff.html`
- Examine changes in marketplace state

**Request approval:**
```bash
node tools/snapshot.js request-approval --branch main
```

**Approve and promote baseline:**
```bash
npm run snapshot:approve -- --approved-by "your-name" --reason "Updated plugin XYZ"
```

Or with full CLI:
```bash
node tools/snapshot.js approve --approved-by "your-name" --reason "Updated plugin XYZ"
```

### 4. CI Integration
After approval is recorded locally:
```bash
git add tools/snapshot-baselines/<branch>.json tools/snapshot-diffs/<branch>.approval.json
git commit -m "Approve marketplace baseline update"
git push
```

The updated baseline and approval record are cached per branch, enabling:
- Historical tracking of baseline changes
- Approval audit trail (who approved, when, why)
- Per-branch baseline isolation

## Approval Record Structure
```json
{
  "status": "approved",
  "branch": "main",
  "latestRunId": "1234567",
  "requestedAt": "2024-01-15T10:30:00.000Z",
  "approvedAt": "2024-01-15T10:35:00.000Z",
  "approvedBy": "alice",
  "reason": "Updated plugin XYZ with new features"
}
```

## NPM Scripts

| Command | Purpose |
|---------|---------|
| `npm run test:ui` | Run Playwright tests |
| `npm run snapshot` | Run snapshot CLI (no args shows help) |
| `npm run snapshot:diff` | Compare to baseline (CI default) |
| `npm run snapshot:approve` | Promote approved snapshot as baseline |
| `npm run snapshot:request-approval` | Flag snapshot as needing approval |
| `npm run snapshot:list-approvals` | Show pending/recent approvals |
| `npm run snapshot:history` | List all historical snapshots |
| `npm run snapshot:analyze` | Generate architecture intelligence report |

## Approval Bypass (Emergency)
For emergencies, bypass approval checks:
```bash
node tools/snapshot.js approve --skip-approval-check
```

**Use sparingly** — approval records are still created for audit trail.

## Storage Locations

| Path | Purpose |
|------|---------|
| `tools/snapshot-baselines/<branch>.json` | Current branch baseline |
| `tools/snapshot-history/<branch>/<runId>.json` | Per-run snapshot archive |
| `tools/snapshot-diffs/<branch>-<runId>-diff.html` | Side-by-side diff report |
| `tools/snapshot-diffs/<branch>-<runId>-analysis.html` | Architecture intelligence report |
| `tools/snapshot-diffs/<branch>.approval.json` | Approval status & audit trail |
| `tools/snapshot-diffs/<branch>-index.html` | Historical index page |

## Troubleshooting

**Q: "Approval required. Run: node tools/snapshot.js approve..."**
- A: Differences were detected. Review the diff report and approve changes.

**Q: "No approval record found for branch"**
- A: This is normal for first run. Run snapshot diff first, then approve.

**Q: CI still fails after approving locally**
- A: Ensure approval record is committed and pushed: `git add tools/snapshot-diffs/<branch>.approval.json`

**Q: Want to see what changed?**
- A: Open the diff report: `tools/snapshot-diffs/<branch>-<runId>-diff.html`
