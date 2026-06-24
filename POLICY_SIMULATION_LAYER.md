# Policy Simulation Layer

**Version 1.0** — Predictive Architecture Engine

## 🎯 Purpose

The Policy Simulation Layer prevents unsafe enforcement transitions by simulating what would happen if you changed your enforcement mode (e.g., from `warning` to `error`). It:

- ✅ **Predicts CI failures** before they happen
- ✅ **Prevents enforcement surprises** by showing exact impact
- ✅ **Enables safe experimentation** with strict modes
- ✅ **Guides migration** with actionable recommendations
- ✅ **Tracks compliance progress** over time

## 🚀 Quick Start

### Simulate Transition to Strict Mode

```bash
php tools/policy-simulator.php --commits=5 --profile=strict
```

Output shows:
- How many violations would **block** under strict mode
- Which files/rules are affected
- Migration recommendations
- Next steps to fix violations

### Analyze More Commits

```bash
php tools/policy-simulator.php --commits=20 --profile=error
```

### View Current Compliance

```bash
php tools/policy-simulator.php --profile=warning
```

## 📊 Report Sections

### Summary
- **Current violations**: What's flagged in your current enforcement mode
- **Target violations**: What would be flagged if you switched modes
- **Impact delta**: Net change in blocking violations

### Violations by Severity
- **Errors**: Hard rule violations (always block)
- **Warnings**: Guidance violations (block only in error mode)

### Violations by Rule
Breakdown of each policy rule:
```
no_cms_imports (error):              5 violations
no_business_logic (warning):         2 violations
require_rfc_for_control_plane:      8 violations
```

### Most Affected Files
Top 5 files with most violations, to prioritize fixes.

### Migration Recommendations

**Three scenarios:**

1. **✓ Safe to transition immediately**
   - Zero violations detected
   - Ready to change enforcement mode now

2. **⚠ Ready for gradual transition**
   - ≤3 violations remaining
   - Fix violations in next 1-2 PRs, then enable error mode

3. **✗ Hold on enforcement transition**
   - \>3 violations remain
   - Use warning mode for 2-3 release cycles
   - Then fix systematically before enforcement

## 🔄 Typical Workflow

### Phase 1: Warning Mode (Current State)
```bash
# Monitor compliance
php tools/policy-simulator.php --profile=warning
# Output: "X violations detected (non-blocking)"
```

### Phase 2: Plan Transition
```bash
# See what would break in strict mode
php tools/policy-simulator.php --profile=strict
# Output: "Y violations would block under error mode"
```

### Phase 3: Fix Violations Incrementally
- Create issues for each violation
- Fix over 1-2 release cycles
- Re-run simulator to track progress

### Phase 4: Transition to Error Mode
```bash
# Verify zero violations
php tools/policy-simulator.php --profile=error
# Output: "✓ Safe to transition to error mode immediately"

# Update policy
# enforcement.mode: "error"  # in CONTROL_PLANE_POLICY.yml

# Commit and push
git add CONTROL_PLANE_POLICY.yml
git commit -m "Enable error-mode enforcement in policy"
git push
# Now CI will block PRs with violations
```

## 📋 Integration with CI/CD

### Option 1: Pre-Merge Validation
Run in CI workflow before allowing mode changes:

```yaml
- name: Simulate enforcement transition
  if: github.event.pull_request.body contains 'enforcement.mode'
  run: |
    php tools/policy-simulator.php --commits=50 --profile=error
    if [ $? -ne 0 ]; then
      echo "Cannot change enforcement mode: violations remain"
      exit 1
    fi
```

### Option 2: Progress Dashboard
Generate reports periodically to track compliance:

```bash
# Weekly cron job
php tools/policy-simulator.php --commits=100 > reports/compliance-$(date +%Y-%m-%d).txt
```

### Option 3: Enforcement Mode Change Guard
Gate enforcement changes on simulation results:

```yaml
jobs:
  enforce-mode-change:
    if: paths contains 'CONTROL_PLANE_POLICY.yml' && enforcement.mode changed
    steps:
      - run: |
          OLD_MODE=$(git show HEAD:CONTROL_PLANE_POLICY.yml | grep "mode:" | head -1)
          NEW_MODE=$(cat CONTROL_PLANE_POLICY.yml | grep "mode:" | head -1)
          if [ "$OLD_MODE" != "$NEW_MODE" ]; then
            php tools/policy-simulator.php --commits=50 --profile=$NEW_MODE
            # Must pass simulation before allowing merge
          fi
```

## 🔍 How It Works

### 1. Load Policy
Reads `CONTROL_PLANE_POLICY.yml` and extracts:
- Current enforcement mode
- All rules (enabled/disabled, severity, conditions)
- Enforcement profiles (strict, permissive, etc.)

### 2. Analyze Commits
Scans recent git commits (default: last 5):
- Parses unified diff format
- Identifies modified files
- Checks each file against boundary rules

### 3. Evaluate Boundary Rules
For each file, applies rules:
- `no_cms_imports`: Check if control-plane file imports CMS code
- `no_business_logic`: Check for tenant/order/customer patterns
- `require_rfc_for_control_plane`: Detect control-plane changes

### 4. Determine Violation Severity
Maps each violation to rule severity:
- **Error**: Always blocks (in any mode except disabled)
- **Warning**: Only blocks in error mode

### 5. Simulate Modes
Runs same checks under two modes:
- **Current mode** (usually `warning`): Shows non-blocking violations
- **Target mode** (usually `error`): Shows what would block

### 6. Generate Report
Outputs analysis with migration recommendations.

## 🛠️ Customization

### Change Number of Commits Analyzed
```bash
php tools/policy-simulator.php --commits=50
```

### Simulate Different Profiles
```bash
php tools/policy-simulator.php --profile=warning
php tools/policy-simulator.php --profile=error
php tools/policy-simulator.php --profile=strict  # alias for error
php tools/policy-simulator.php --profile=permissive  # alias for warning
```

### Parse Commit Diff Intelligently
Edit `parseGitDiff()` function to extract more context (e.g., actual code changes, not just file names).

Edit `checkBoundaryRules()` function to add custom rule detection:

```php
elseif ($ruleName === 'my_custom_rule') {
    if (/* your condition */) {
        $issue = [
            'rule' => $ruleName,
            'message' => $rule['message'] ?? 'Custom message',
        ];
    }
}
```

## 📈 Compliance Tracking

Track progress over time:

```bash
# Weekly cron job
for week in {1..12}; do
  php tools/policy-simulator.php --commits=100 --profile=error \
    > reports/week-$week.txt
done

# Compare reports to see compliance improving
```

## ⚠️ Known Limitations

1. **Heuristic-based detection**: Rules use filename/pattern matching, not AST analysis
   - Improvement: Parse actual PHP code to detect business logic more accurately
   
2. **Git-based only**: Analyzes committed code, not uncommitted changes
   - Improvement: Could extend to analyze staged files in working directory

3. **No RFC validation**: `require_rfc_for_control_plane` can't verify RFC existence
   - Improvement: Integrate with GitHub API to check PR body for RFC links

4. **Static profiles**: Can't simulate custom enforcement configurations
   - Improvement: Accept custom YAML configuration for simulation

## 🚦 Safety Guarantees

The simulator **does not**:
- Modify any files
- Run enforcement
- Block PRs
- Change your policy

The simulator **only**:
- Reads git history
- Reads policy file
- Generates report
- Provides recommendations

Safe to run anytime, anywhere—it's read-only analysis.

## 📞 Next Steps

1. **Run simulator on your codebase**:
   ```bash
   php tools/policy-simulator.php --commits=5 --profile=strict
   ```

2. **Review report** for violations that would block in error mode

3. **Plan fixes** if any violations found

4. **Track progress** by running simulator weekly

5. **Schedule enforcement mode change** once zero violations:
   ```yaml
   enforcement:
     mode: "error"  # from "warning"
   ```

---

**Built on**: CONTROL_PLANE_POLICY.yml (single source of truth)
**Used by**: Policy governance system, CI/CD pipelines, architecture teams
**Status**: Production-ready, zero-friction enforcement transitions
