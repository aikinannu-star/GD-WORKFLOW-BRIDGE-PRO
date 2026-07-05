# OpenAPI Canonical Contract

This folder is the starting point for Phase 1.1: move the API contract to OpenAPI-first.

## Goal

- Complete the current endpoint inventory from `services/marketplace/server.php`.
- Define a reusable schema library in `openapi/components/schemas/`.
- Produce a canonical `openapi/openapi.yaml` root spec.
- Use the spec as the source of truth for API implementation, SDK generation, and compatibility gating.

## Current status

- `API_INVENTORY.md` contains the extracted endpoint inventory and initial schema library plan.
- `openapi/openapi.yaml` is now the canonical root OpenAPI spec.
- `openapi/components/schemas.yaml` contains the shared schema library for marketplace and operational intelligence APIs.
- Next work:
  1. Create `openapi/paths/` files for each API domain if further modularization is desired.
  2. Add additional schema refinements and ensure generated SDKs match implementation expectations.
