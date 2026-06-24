<!--
  Request for Comments (RFC) template for architectural proposals.
  Use this for designs that:
  - Touch control-plane boundaries (gateway, auth, invalidation, supervisor).
  - Introduce new services or inter-service contracts.
  - Change authorization semantics or caching behavior.
  - Propose architectural changes (e.g., JWT, mTLS, service mesh).
  
  RFC is NOT required for:
  - Bug fixes in non-control-plane code.
  - New business features in the application plane (CMS, projects, etc).
  - Incremental improvements within existing patterns.
  
  For control-plane changes, open RFC before implementation to align with @aikinannu-star (Control Plane owners).
-->

# RFC: [Proposal Title]

## Summary

One-sentence summary of the proposal.

## Motivation

Why is this change needed? What problem does it solve?
- User or system level motivation
- Current pain point or gap

## Detailed Design

### Architecture

How does this fit into the existing system? Include diagrams if helpful:
- Gateway interaction (if applicable)
- Service boundaries (Application Plane vs Control Plane)
- Cache invalidation or pub/sub changes (if applicable)

### Interfaces / Contracts

What new or changed endpoints, message formats, or APIs are introduced?

```yaml
Example:
  Endpoint: POST /api/v1/gateway/config/reload
  Request: { tokens: [...], config_version: "2.1" }
  Response: { status: "ok", applied_at: "..." }
  Ownership: Control Plane (requires @aikinannu-star review)
```

### Control Plane Boundary Implications

**Reference:** [CONTROL_PLANE_BOUNDARY.md](../CONTROL_PLANE_BOUNDARY.md)

- Does this proposal violate any boundary rules? (List which rules or state "none")
- If it requires new interactions between Control Plane and Application Plane, describe the HTTP / pub/sub / event contract.
- If it introduces new dependencies, are they to stable, versioned APIs or to internal CMS code? (Must be stable APIs only)

### Backward Compatibility

- Is this a breaking change? If yes, migration/deprecation plan.
- Can we implement this behind a feature flag?

### Security & Observability

- How is this observable (logs, metrics, traces)?
- Any new secrets or credentials required? (Document in `.env.example`)
- How is this tested?

## Implementation Plan

### Phase 1: [Feature / Spike / Proof of Concept]
- [ ] Design and RFC approval by Control Plane owners
- [ ] Unit tests added to `tests/auth/` or `tests/integration/`
- [ ] Local validation

### Phase 2: [Integration / CI]
- [ ] CI job added or modified (update `.github/workflows/auth-tests.yml` if needed)
- [ ] Branch protection updated if new checks required
- [ ] Rollout plan documented

### Phase 3: [Rollout / Deprecation]
- [ ] Staged rollout strategy (if applicable)
- [ ] Monitoring and rollback plan
- [ ] Documentation update

## Testing Strategy

- Unit tests: where and what coverage?
- Integration tests: which services involved?
- How do you verify backward compatibility (if applicable)?

## Rollout & Rollback

- Rollout strategy: all-at-once, canary, feature flag?
- Rollback trigger: what metrics/logs indicate failure?
- Rollback procedure: manual or automated?

## Alternatives Considered

What other designs were evaluated and why were they rejected?

## References

- Related RFCs or issues
- External references (JWT specs, Kubernetes docs, etc.)

## Sign-off

**RFC Author:** @[you]

**Control Plane Owner Review:** (Required for control-plane proposals)
- [ ] @aikinannu-star approval

**Follow-up PR:** [Link PR here once implementation begins]

---

**How to use this RFC:**

1. Copy this template into a new issue or discussion thread.
2. Fill in all sections and link from the issue/PR.
3. For control-plane proposals, tag @aikinannu-star and request feedback.
4. Once approved, open a PR and reference the RFC issue number.
5. Update the RFC with final implementation link and any decisions made during implementation.
