# Control Plane vs Application Plane — Boundary Rules

This document formalizes the boundary between the Control Plane (gateway, authorization, invalidation, metrics, supervisor) and the Application Plane (CMS, business logic, project data).

Goals
- Keep security-critical code (control plane) stable, minimal, and auditable.
- Prevent accidental coupling of control-plane primitives to CMS business logic.
- Define allowed interaction patterns and enforcement mechanisms.

Principles (high-level)
- Single Responsibility: Control Plane implements cross-cutting operational/security primitives only.
- No Business Logic in Control Plane: Control Plane must never implement tenant/business workflows or depend on CMS internals.
- Stable, explicit interfaces: interactions must go through well-documented HTTP endpoints, message channels, or signed events.
- Minimal runtime privileges: Control Plane components run with least privilege required.

Control Plane Responsibilities (allowed)
- Routing, preflight authorization and decision-caching (gateway).
- Authorization primitives (AccessGraph, PermissionService) and in-memory/file/Redis caches.
- Invalidation propagation (publish/subscribe) and supervisory processes that maintain availability.
- Metrics, health checks, auditing, and operational telemetry for control primitives.
- Token/credential validation for control endpoints (control-plane tokens, future JWT/mTLS validations).
- Control-plane-only helper libraries (e.g., `services/lib/ControlPlaneAuth.php`, `services/lib/Metrics.php`, `services/lib/AccessGraph.php`).

Application Plane Responsibilities (forbidden in Control Plane)
- CMS business rules, feature logic, billing flows, or tenant-specific transformations.
- Direct read/write of application domain persistence for business data (projects, orders, customer data) inside Control Plane code.
- Importing CMS-only libraries or models into control-plane code.

Allowed Interaction Patterns
1. Request/Response over HTTP
   - Gateway -> CMS: preflight authorization call to `/api/v1/cms/authorize` (stateless); CMS returns yes/no and optional meta.
   - All control-plane calls must accept and validate only minimal inputs (user id, project id, action), and must not transfer heavy business state.
2. Cache invalidation via Pub/Sub
   - CMS publishes invalidation messages; gateway-side subscribers update cache/metrics. Messages must carry only identifiers (user_id, project_id, action) and a small monotonic sequence or timestamp.
3. Read-only introspection
   - Control Plane may read graph snapshots or limited read-only exports produced by CMS (e.g., `graph.json` export). These exports must be canonicalized and not reference live CMS internals.
4. Event-driven (signed)
   - If richer context is required, events must be signed and validated; payloads must remain minimal and schema versioned.

Forbidden Dependencies (hard rules)
- Control Plane must NOT:
  - include/require files under `includes/*` or other CMS business code directories.
  - call CMS internal functions directly (only HTTP/API or pub/sub allowed).
  - access application DB tables for business logic (reads limited to public snapshots/exports only).

Testing, Review, and Enforcement
- Tests
  - Control Plane code MUST have unit tests and be included in `Auth Tests` or an appropriate CI job.
  - Any change touching `services/gateway/`, `services/lib/ControlPlaneAuth.php`, or `services/lib/AccessGraph.php` must add/modify tests demonstrating intended behavior and include regression coverage.
- Reviews
  - `CODEOWNERS` requires Control Plane owners for gateway/control-plane files (configured).
  - PR template must include a Control Plane Impact section and require explicit reviewers for Control Plane changes.
- CI
  - `Auth Tests` workflow must pass on PRs and pushes before merging to protected branches.

Versioning and Schema
- All control-plane APIs, pub/sub message formats, and cached key derivation must be explicitly versioned (semantic schema version in payloads or documented change logs).
- Backward-incompatible changes to control-plane contracts require a migration plan and a deprecation window.

Migration & Upgrades
- When migrating control-plane auth (JWT/mTLS): implement feature flag and a dual-mode validator that supports old token plus new mechanism; add tests and rollout plan.
- When splitting services to Kubernetes: ensure control-plane components maintain leader-election or supervisor semantics and preserve single-source-of-truth for invalidations.

Developer Checklist (PR author)
- [ ] Does this change touch control-plane code? If yes, add Control Plane reviewers and tests.
- [ ] Does this change import application/business modules? If yes, refactor to use API or pub/sub instead.
- [ ] Did you update `BRANCH_PROTECTION.md` / `CONTROL_PLANE_BOUNDARY.md` if you changed required rules?
- [ ] Did you run `php tests/auth/run-auth-tests.php` locally and ensure coverage for changed behavior?

Appendix: Examples
- Good: Gateway calls `POST /api/v1/cms/authorize` with `user_id`, `project_id`, `action` and caches boolean decision in Redis.
- Good: CMS publishes `gateway:cms:auth:invalidate` with `{user_id, project_id, action, ts}` and a subscriber logs/metrics the invalidation.
- Bad: Gateway `require_once` a CMS model file and performs business validation of an order.

Contact
- For proposals to change the boundary rules, open an RFC PR and tag `Control Plane` owners for design discussion.

*** End of Document
