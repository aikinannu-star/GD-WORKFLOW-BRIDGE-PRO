# Branch protection and required checks

This repository should enforce branch protection on the primary branches (`main`/`master`) to ensure the control-plane auth tests and related checks run before merging.

Recommended settings (GitHub -> Settings -> Branches -> Branch protection rules):

- Branch name pattern: `main` (and/or `master`)
- Require pull request reviews before merging: **enabled** (1 or 2 reviewers recommended)
- Require status checks to pass before merging: **enabled**
  - Select these checks to require:
    - `Auth Tests` (GitHub Actions job: `auth-tests`)
    - Any existing CI jobs you rely on (e.g., `CI`, `license-server-ci`)
- Require branches to be up to date before merging: **enabled** (optional but recommended)
- Include administrators: **enabled** (to avoid accidental bypass by admins)
- Restrict who can push to matching branches: **optional** (use if you want to limit pushes to CI or specific teams)

Notes
- The `Auth Tests` workflow is defined at `.github/workflows/auth-tests.yml`. It runs the auth test runner and is triggered on PRs and pushes to `main`/`master`.
- If you rename branches, update the protection rule to match the branch name used for releases.
- To require the Actions job by name, make sure the workflow's job name stays `auth-tests` and the workflow completes successfully on the PR.

Example quick steps

1. Go to the repository on GitHub.
2. Settings -> Branches -> Add rule.
3. Set `Branch name pattern` to `main`.
4. Enable `Require pull request reviews before merging` and `Require status checks to pass before merging`.
5. Select `Auth Tests` in the list of checks (you may need to run the workflow once to appear).
6. Save changes.

Security rationale

Requiring the `Auth Tests` workflow ensures the control-plane auth primitive (`ControlPlaneAuth`) and surrounding middleware cannot regress without CI validation, preserving the security boundary enforced by the gateway and avoiding silent failures in production.
