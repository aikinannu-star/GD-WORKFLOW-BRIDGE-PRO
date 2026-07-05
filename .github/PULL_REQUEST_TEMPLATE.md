<!--
  Pull Request template to enforce Control Plane review and required checks.
  Contributors: fill the sections below and ensure required checks pass before merging.
-->

# Summary

Provide a short summary of the change and why it is needed.

## Motivation

Explain the user-visible or system-level motivation for this change.

## Changes

- What files were changed? (list)
- Any new public APIs or environment variables? If yes, document them.

## Security / Control Plane Impact

**Reference:** [CONTROL_PLANE_BOUNDARY.md](../CONTROL_PLANE_BOUNDARY.md)

- Does this change touch the Control Plane or Gateways? (required)
  - If YES: add the `Control Plane` label and request an explicit review from @aikinannu-star (Control Plane owners).
  - If YES: include reasoning why this change is safe and how it preserves `ControlPlaneAuth` guarantees.
  - If touching `services/gateway/`, `services/lib/ControlPlaneAuth.php`, or `services/lib/AccessGraph.php`: Is this an RFC-worthy change? (see `.github/RFC_TEMPLATE.md`)

### Boundary Compliance Checklist (for Control Plane changes)

If your change touches control-plane code, verify:

- [ ] Does this import any CMS business code (`includes/*`)? If yes, refactor to use HTTP API or pub/sub instead.
- [ ] Does this add new business logic to the gateway? If yes, move logic to CMS and call via HTTP.
- [ ] Does this add new dependencies? Are they to stable, versioned APIs only (not internal CMS internals)?
- [ ] Does this change authentication semantics or cache invalidation? If yes, include tests demonstrating behavior and edge cases.

## Tests

- How was this change tested? (unit/integration/manual)
- **Required:** Ensure `Auth Tests` pass locally: `php tests/auth/run-auth-tests.php`.
- If touching control-plane code: add or update tests in `tests/auth/` to cover new behavior.

## Rollout / Backout

- Any migration steps or rollout notes?
- For control-plane changes: document backward-compatibility strategy or deprecation plan.
- Backout plan: how do we revert this safely if it causes issues?

## Checklist (required for all PRs)

- [ ] I have read [CONTROL_PLANE_BOUNDARY.md](../CONTROL_PLANE_BOUNDARY.md) and verified my change complies with boundary rules.
- [ ] I have updated `BRANCH_PROTECTION.md` if this requires changes to branch rules.
- [ ] `Auth Tests` pass locally and on CI (see `.github/workflows/auth-tests.yml`).
- [ ] I added reviewers with `Control Plane` expertise if the change touches gateway, auth, metrics, or invalidation.
- [ ] I have documented new environment variables in `.env.example` if any are added/changed.
- [ ] For control-plane proposals: I opened an RFC issue first (see `.github/RFC_TEMPLATE.md`) if this is a significant architectural change.

**⚠️ CRITICAL:** If your change touches `services/gateway/`, `services/lib/ControlPlaneAuth.php`, `services/lib/AccessGraph.php`, or involves control-plane contracts, **do not merge without explicit approval from @aikinannu-star (Control Plane owners).** Violations of [CONTROL_PLANE_BOUNDARY.md](../CONTROL_PLANE_BOUNDARY.md) must be addressed before merge.
