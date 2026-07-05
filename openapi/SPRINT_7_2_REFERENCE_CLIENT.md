# Sprint 7.2: Reference Client DX Validation Plan

**Objective**: Validate the generated TypeScript SDK as a true developer experience by building a clean consumer application that uses only the published/generated SDK.

**Timeline**: 1-2 weeks  
**Blocker for**: SDK publication to npm  
**Success Criteria**: Reference client proves the SDK is usable, ergonomic, and complete for real end-to-end workflows without SDK workarounds.

---

## Why This Is a DX Validation Suite

A generated SDK compiling is only a contract sanity check. A reference client is the real test of whether the SDK is:

- usable without manual patches
- complete across workflow surfaces
- stable in error conditions
- configured only through environment variables
- free from internal platform dependencies

If the reference client needs a direct HTTP call or imports any internal platform library, that is actionable feedback for improving the SDK or API contract.

---

## Phase A — Foundation: Clean Consumer Application

The reference client must be a clean consumer app with these rules:

- Uses only the generated/published TypeScript SDK
- No direct HTTP calls except via the SDK
- No importing internal platform or server-side libraries
- Configuration only through environment variables
- No access to server internals or implementation details
- No custom plumbing that bypasses the SDK

### Reference Client Structure

```
reference-client/
├── package.json
├── tsconfig.json
├── .env.example
├── README.md
├── src/
│   ├── index.ts
│   ├── config.ts
│   ├── auth/
│   │   └── authenticate.ts
│   ├── workflows/
│   │   ├── marketplace.ts
│   │   ├── tenant.ts
│   │   ├── intelligence.ts
│   │   ├── operations.ts
│   │   ├── remediation.ts
│   │   └── governance.ts
│   └── examples/
│       ├── marketplace/
│       │   ├── browse.ts
│       │   ├── install-plugin.ts
│       │   └── verify-installation.ts
│       ├── intelligence/
│       ├── operations/
│       ├── remediation/
│       └── governance/
└── tests/
    ├── workflow.test.ts
    └── error-handling.test.ts
```

---

## Phase B — End-to-End Workflows

The reference client should implement complete user journeys, not isolated endpoint calls. Each journey should be executable and demonstrate the SDK as a real application layer.

### Workflow 1: Marketplace
- Browse marketplace products
- View product details
- Install a plugin
- Verify installation status
- Optionally uninstall or clean up

### Workflow 2: Tenant Operations
- Select tenant
- View tenant health
- View tenant trends
- View intelligence metrics for the tenant

### Workflow 3: Governance
- Create a snapshot or baseline
- Detect a diff / drift
- Request approval
- Approve baseline through workflow

### Workflow 4: Operations Center
- View fleet KPIs
- Open drift analysis
- Review learning insights

### Workflow 5: Remediation
- Discover an issue
- Preview remediation recommendations
- Execute remediation
- Verify health improvement or outcome

Each workflow should use only SDK methods and should be written as a reusable, documented example.

---

## Phase C — Error Handling and Predictable Failures

Strong DX is revealed through failure scenarios. The SDK should expose a consistent, typed error model rather than raw HTTP responses.

### Target error scenarios
- invalid tenant IDs
- duplicate plugin slugs
- authorization failures
- malformed requests
- network interruptions
- timeouts
- API version mismatches
- rate limiting

### Validation goals
- Errors are catchable in a consistent shape
- Status codes and error messages are meaningful
- Validation failures are distinguishable from server failures
- SDK consumers can implement retry and fallback logic
- No raw `any` errors leak through the SDK surface

---

## Phase D — Developer Onboarding

The reference client must be built as an onboarding suite, not a demo.

### Recommended structure

```
reference-client/
├── examples/
│   ├── marketplace/
│   ├── intelligence/
│   ├── operations/
│   ├── remediation/
│   └── governance/
├── src/
├── tests/
└── README.md
```

### Example expectations
- Each example is executable from the command line
- Each example maps to a real workflow
- Each example is standalone and self-documenting
- Each example doubles as regression coverage
- The README explains setup and how to run examples in minutes

---

## Implementation Plan

### Step 1: Project Setup (0.5 days)
- Create a clean Node.js + TypeScript consumer app
- Add only the generated SDK dependency
- Use environment variables for configuration
- Do not add any SDK workarounds or internal platform imports

### Step 2: SDK-only Layers (0.5 days)
- Implement `src/config.ts` to read env vars
- Implement `src/auth/authenticate.ts` to return an SDK client
- Implement `src/workflows/*` as reusable journey components
- Keep all HTTP and transport logic inside the generated SDK

### Step 3: Workflow Implementation (3-4 days)
- Build the five major workflows above
- Prefer full flows over single endpoint calls
- Use the SDK to verify installation, drift, approvals, remediation
- Document each workflow in the example directory

### Step 4: Error Validation (1-2 days)
- Write tests for targeted failure cases
- Validate SDK returns consistent error objects
- Confirm the SDK surfaces typed errors for invalid inputs
- Ensure developer-facing guidance is present in README

### Step 5: Examples + Onboarding (1 day)
- Create executable examples under `examples/`
- Document quickstart in README
- Example names should map to real tasks:
  - `marketplace/browse.ts`
  - `marketplace/install.ts`
  - `tenant/health.ts`
  - `intelligence/metrics.ts`
  - `operations/kpis.ts`
  - `remediation/execute.ts`
  - `governance/snapshot.ts`

### Step 6: Integration and Regression Tests (1 day)
- Add end-to-end workflow tests in `tests/`
- Add error-handling tests
- Ensure tests use only the SDK
- Validate example scripts compile and run
- Integrate `npm test` into CI after Sprint 7.2

---

## Success Criteria

- [ ] Reference client project created and builds cleanly
- [ ] Uses only the generated TypeScript SDK
- [ ] No direct HTTP calls outside the SDK
- [ ] No internal platform libraries imported
- [ ] Configuration only via environment variables
- [ ] Zero SDK modifications required by the reference client
- [ ] No undocumented API behavior discovered
- [ ] Consistent error model across endpoints
- [ ] Complete workflow coverage for major capabilities
- [ ] Executable examples that double as regression tests
- [ ] README enables a new developer to get started in minutes
- [ ] Integration tests pass against live API

---

## If Issues Are Found

Do not fix the reference client by bypassing the SDK. Instead:

1. Record the exact SDK/API gap
2. Update the OpenAPI contract or SDK generator
3. Regenerate the SDK
4. Re-run the reference client

That keeps the SDK surface honest and improves developer experience for everyone.

---

## After Success: SDK Publication

Once this DX validation suite passes:

1. Publish the TypeScript SDK to npm
2. Generate and validate the JavaScript SDK
3. Generate and validate the PHP SDK
4. Add SDK integration tests to CI so SDK regeneration, build, and smoke tests run on contract changes

This makes the SDKs part of the governance pipeline rather than separate deliverables.

---

## Recommended Deliverables

- `reference-client/` project scaffold
- `examples/` with real workflows and runnable scripts
- `tests/` with workflow and error regression coverage
- `README.md` with quickstart and run commands
- `CI` hook for executing the reference client smoke tests
- `compatibility matrix` guidance for release tracking

---

## Developer Validation Focus

This plan turns Sprint 7.2 from a demo into a DX validation suite. The goal is not just "does the SDK work?" but "can a developer be productive with this SDK in minutes, without breaking the rules of a clean consumer app?"


4. **Update developer portal** with links to reference client and generated SDKs

5. **Begin Sprint 7.3: Observability**

---

## Repository Structure

```
gd-workflow-bridge-pro/
├── reference-client/          ← NEW (this sprint)
│   ├── src/
│   ├── tests/
│   ├── examples/
│   ├── package.json
│   └── README.md
├── openapi/
│   └── ...existing files
├── build/sdk-typescript/      ← Used by reference client
└── ...
```

---

## Timeline

| Phase | Days | Milestone |
|-------|------|-----------|
| 1: Setup | 0.5 | Project scaffolded, SDK installed |
| 2: Auth | 1 | Authentication works |
| 3: Workflows | 3-4 | All 6 workflows functional |
| 4: Error Handling | 2 | Edge cases documented |
| 5: Examples | 2 | Developer-ready examples |
| 6: Tests | 2 | Integration tests passing |
| **Total** | **10-11 days** | **SDK Validated** |

**Ready for SDK publication and Sprint 7.3** ✅

---

## Key Principle

> The reference client is **not an internal demo**. It is the **gold standard** for how external developers should use the SDK. Every decision in the reference client should be defensible as "best practice for SDK consumers."

If the reference client works flawlessly, the SDK is ready for public consumption.
