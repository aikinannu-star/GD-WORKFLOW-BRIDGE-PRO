# Reference Client

This reference client validates the generated TypeScript SDK as a clean consumer application.

## Goals

- Use only the generated SDK (`@gd-workflow-bridge-pro/api-sdk`)
- No direct HTTP calls except through the SDK
- No internal platform library imports
- Configuration only via environment variables
- Demonstrate real end-to-end workflows
- Validate error handling and onboarding

## Setup

```bash
cd reference-client
npm install
cp .env.example .env.local
# edit .env.local with API_BASE_URL, API_TOKEN, TENANT_ID
```

## Run

```bash
npm run dev
```

## Examples

Use the example scripts to run workflows:

```bash
npm run example:marketplace
npm run example:intelligence
npm run example:learning
npm run example:platform
npm run example:tenant-health
```

These examples all use the generated SDK through the reference client wrapper. They prove the developer experience by executing real end-to-end workflows without any direct HTTP or raw Axios usage.

## Tests

```bash
npm test
```

## Validation Rules

- If the reference client needs a direct HTTP request, the SDK or contract must be improved.
- If the reference client needs internal imports, the SDK surface is incomplete.
- All configuration must live in environment variables.

## Developer Experience Scorecard

| Capability | Status |
| --- | --- |
| SDK compiles | ✅ |
| Strict TypeScript | ✅ |
| Wrapper layer over generated client | ✅ |
| Runnable examples | ✅ |
| Typed error model (`SdkError`) | ✅ |
| Marketplace workflow validation | ⏳ |
| Tenant workflow validation | ⏳ |
| Operations workflow validation | ⏳ |
| Remediation workflow validation | ⏳ |
| No direct HTTP in examples | ✅ |
| CI workflow validation | ⏳ |

## Structure

- `src/` — client bootstrap, configuration, authentication, and workflow orchestration
- `src/auth/` — authentication helper
- `src/workflows/` — reusable workflow implementations
- `src/examples/` — executable examples for developer onboarding
- `tests/` — integration and error regression tests
