# Policy Simulation Layer — Quick Start Guide

## What Problem Does It Solve?

**Before**: You want to switch enforcement from WARNING to ERROR mode (blocking violations)
- ❌ You don't know if it will break existing PRs
- ❌ You don't know which files will be affected
- ❌ You can't predict which developers' code will fail
- ❌ You switch it on and CI breaks unexpectedly

**After**: The Policy Simulation Layer shows you exactly what would break
- ✅ See violations that would block under error mode
- ✅ Know which files/rules are affected
- ✅ Plan fixes before enforcement
- ✅ Transition safely with zero surprises

## 5-Minute Walkthrough

### 1. Check Current Compliance

```bash
php tools/policy-simulator.php --commits=5 --profile=strict
```

**Output:**
```
Current violations (warning mode):  0
Target violations (error mode):     0
Impact of transition:               No change

✓ Safe to transition to error mode immediately
  No violations detected under target profile
```

**What this means**: Your code is already compliant with strict enforcement. Safe to switch now.

### 2. See More Details (if violations exist)

```bash
php tools/policy-simulator.php --commits=50 --profile=error
```

**Example output (if violations existed):**
```
Current violations (warning mode):  3
Target violations (error mode):     5
Impact of transition:               +2 newly blocking violations

⚠ Ready for gradual transition
  5 violations to resolve before enforcement
  Plan: Fix violations in upcoming PRs, then enable error mode

MOST AFFECTED FILES
----
services/gateway/auth-cache.php:     3 violations
includes/Admin/AccessControl.php:    2 violations

DETAILED VIOLATIONS (TARGET MODE)
----
services/gateway/auth-cache.php
  Rule:     no_cms_imports
  Severity: error
  Message:  CMS imports not allowed in control-plane files
  Commit:   a1b2c3d (John Doe)
  Subject:  Add caching layer to gateway
```

### 3. Understand What Would Break

The simulator tells you three scenarios:

**Scenario 1: Safe to Transition Now** ✓
```
Zero violations detected
→ Switch enforcement mode immediately
→ No code changes needed
→ Run: enforcement.mode = "error" in policy
```

**Scenario 2: Ready for Gradual Transition** ⚠
```
1-3 violations remaining
→ Fix violations in next 1-2 PRs
→ Verify zero violations
→ Then switch enforcement mode
→ Estimated: 1-2 week timeline
```

**Scenario 3: Hold on Enforcement** ✗
```
4+ violations remaining
→ Use warning mode for 2-3 release cycles
→ File issues for each violation
→ Fix systematically
→ Track progress with simulator
→ Only switch after zero violations
→ Estimated: 1-2 month timeline
```

## How to Use in Your Workflow

### Weekly: Check Compliance

```bash
# Monday morning check-in
php tools/policy-simulator.php --commits=50 --profile=error

# Output tells you:
# - Are we getting more/fewer violations?
# - Which files need fixes?
# - Ready to enforce yet?
```

### Before Enforcement Change: Run Simulator

```bash
# Want to switch from warning to error?
php tools/policy-simulator.php --profile=error

# If: Zero violations → Safe to change
# Else: Fix violations first, then re-run
```

### In GitHub: Automatic Analysis

When someone changes `CONTROL_PLANE_POLICY.yml` to switch enforcement mode:

1. GitHub Actions automatically runs the simulator
2. Policy Simulator workflow comments on the PR:
   ```
   ⚠️ Enforcement Mode Change Detected
   Transitioning from: warning → error
   
   Simulation Results:
   - Zero violations detected under error mode
   - ✓ Safe to transition
   
   [Full Report...]
   ```
3. If safe: PR can be merged, enforcement takes effect immediately
4. If unsafe: PR is blocked, message explains what needs fixing

## Real-World Example: Safe Transition

### Day 1: Monday Morning

```bash
$ php tools/policy-simulator.php --profile=error

✓ Safe to transition to error mode immediately
  No violations detected under target profile
```

**Decision**: We're ready!

### Day 1: Update Policy

Edit `CONTROL_PLANE_POLICY.yml`:
```yaml
enforcement:
  mode: "error"  # was "warning"
```

Commit and push:
```bash
git add CONTROL_PLANE_POLICY.yml
git commit -m "Enable error-mode enforcement for control-plane"
git push
```

### Day 1: GitHub Actions Runs

Policy Simulator workflow automatically:
1. Runs the simulator on recent commits
2. Confirms zero violations under error mode
3. Auto-comments: "✓ Safe to transition, PR approved"
4. Allows merge to proceed

### Day 1: Enforcement Active

After merging to main:
- All subsequent PRs run linter in ERROR mode
- Any control-plane violations = CI failure
- Developers must fix before merge
- **Architecture lock-in complete**

## Real-World Example: Unsafe Transition (Blocked)

### Day 1: Developer Tries to Enforce

```bash
$ php tools/policy-simulator.php --profile=error

Current violations (warning mode):  3
Target violations (error mode):     7
Impact: +4 newly blocking violations

✗ Hold on enforcement transition
  7 violations would block under error mode
  
MOST AFFECTED FILES:
  services/gateway/cache.php:        4 violations
  includes/Integrations/CMS.php:     3 violations

Cannot transition until violations are fixed.
```

**Decision**: Not ready. Need to fix violations first.

### Days 2-10: Fix Violations

Submit PRs:
- PR #456: Remove CMS imports from gateway → ✅ Merged
- PR #457: Extract business logic from integrations → ✅ Merged

### Day 11: Re-assess

```bash
$ php tools/policy-simulator.php --profile=error

Current violations (warning mode):  0
Target violations (error mode):     0
Impact: No change

✓ Safe to transition to error mode immediately
```

**Decision**: Now we can enforce!

## Command Reference

### Basic Usage
```bash
php tools/policy-simulator.php
# Simulates 5 recent commits against strict (error) mode
```

### With Options
```bash
# Analyze more commits
php tools/policy-simulator.php --commits=20

# Simulate specific profile
php tools/policy-simulator.php --profile=warning
php tools/policy-simulator.php --profile=error
php tools/policy-simulator.php --profile=strict    # alias
php tools/policy-simulator.php --profile=permissive # alias

# Combination
php tools/policy-simulator.php --commits=100 --profile=error
```

### Output Interpretation
```
Current violations: Flagged under YOUR current mode
Target violations:  Flagged under the mode you want to switch to
Impact delta:       How many NEW violations would block

SUMMARY
├─ 0 current, 0 target = ✓ Safe to switch now
├─ 0 current, 1-3 target = ⚠ Ready for gradual transition
└─ 0 current, 4+ target = ✗ Hold enforcement

VIOLATIONS BY SEVERITY
├─ Errors: Always block (any mode except disabled)
└─ Warnings: Only block in error mode

MOST AFFECTED FILES
└─ Top 5 files with violations (fix these first)

MIGRATION RECOMMENDATIONS
└─ Specific guidance: safe/gradual/hold
```

## Integration with CI/CD

### GitHub Actions (Automatic)

When policy changes: Simulator workflow runs automatically
```yaml
.github/workflows/policy-simulator.yml
├─ Triggers: On push/PR to CONTROL_PLANE_POLICY.yml
├─ Runs: PHP simulator with git analysis
├─ Comments: Auto-report on PR
└─ Blocks: Unsafe enforcement mode changes
```

### Local Pre-Commit (Optional)

```bash
# Before committing enforcement changes:
php tools/policy-simulator.php --profile=error

# If safe (0 violations):
#   → Commit enforcement change
# Else:
#   → Abort commit
#   → Fix violations first
```

### Weekly Progress Dashboard (Optional)

```bash
#!/bin/bash
for week in {1..12}; do
  echo "Week $week:"
  php tools/policy-simulator.php --commits=100 \
    | grep "Target violations"
  echo ""
done
```

## FAQ

**Q: How often should I run the simulator?**
A: Weekly during warning mode. Before any enforcement changes.

**Q: What if I want to see violations in detail?**
A: Look at the "DETAILED VIOLATIONS" section. It shows file, rule, commit, author.

**Q: Can I run the simulator on the main branch?**
A: Yes, it analyzes the last N commits from wherever you run it.

**Q: What if the simulator shows violations but I disagree?**
A: The rules are defined in CONTROL_PLANE_POLICY.yml. Update the rule if it's overly strict.

**Q: Can I see which PRs would be blocked?**
A: Not directly, but you can see which files have violations and check git history.

**Q: How do I fix violations?**
A: Depends on the rule (no_cms_imports, no_business_logic, require_rfc). Check CONTROL_PLANE_BOUNDARY.md.

**Q: Can the simulator break my code?**
A: No, it's read-only analysis. It never modifies files or affects CI.

**Q: How long does the simulator take?**
A: Usually 1-2 seconds for 50 commits.

## Next Steps

1. **Run simulator**: `php tools/policy-simulator.php`
2. **Review report**: Understand current violations (if any)
3. **Plan fixes**: If violations found, create issues
4. **Track progress**: Run weekly to monitor compliance
5. **Transition**: When zero violations, enable error mode

---

**Estimated Time to Safe Enforcement**: 
- Zero violations now: 1 day (update policy and merge)
- 1-3 violations: 1-2 weeks (fix in PRs, then enforce)
- 4+ violations: 1-2 months (gradual fix over cycles)

Your codebase currently has **0 violations** → Ready to enforce now ✅
