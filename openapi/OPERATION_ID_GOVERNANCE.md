# Operation ID Governance

## Overview
This document establishes the naming convention and governance rules for all OpenAPI operation IDs across the GD Workflow Bridge Pro API.

## Status
- **62 total operations** across 8 domains
- **59 unique operation IDs** (3 edge-case conflicts in collections)
- **100% coverage** — all endpoints have assigned operation IDs
- **Conflict resolution** — edge cases flagged for manual review

## Naming Convention

All operation IDs follow this pattern:

```
{domain}{verb}{resource}
```

### Components

#### Domain (lowercase, no spaces)
- `driftanalysis` — Drift Analysis
- `health` — Health
- `intelligence` — Intelligence
- `marketplace` — Marketplace
- `platform` — Platform
- `remediation` — Remediation
- `riskzones` — Risk Zones
- `testing` — Testing

#### Verb (title-cased)
- `List` — Collection retrieval (GET without path param)
- `Get` — Single resource retrieval (GET with path param)
- `Create` — Resource creation (POST)
- `Update` — Resource replacement (PUT)
- `Patch` — Resource partial update (PATCH)
- `Delete` — Resource deletion (DELETE)

#### Resource (title-cased, no dashes)
Derived from the endpoint path, removing `/api/v1` and collapsing path segments.

Examples:
- `/api/v1/marketplace/products` → `MarketplaceProducts`
- `/api/v1/marketplace/products/{productId}` → `MarketplaceProducts` (with Get verb)
- `/api/v1/intelligence-learning/performance` → `IntelligenceLearningPerformance`

## Distribution by Domain

| Domain | Operations | Examples |
|--------|-----------|----------|
| Marketplace | 36 | `marketplaceListMarketplaceProducts`, `marketplaceCreateMarketplacePlugins` |
| Intelligence | 13 | `intelligenceListIntelligencehealth`, `intelligenceListIntelligencelearning` |
| Platform | 6 | `platformListMarketplaceplatformdashboard` |
| Risk Zones | 2 | `riskzonesListApiV1riskzones` |
| Remediation | 2 | `remediationListApiV1remediationevents` |
| Drift Analysis | 1 | `driftanalysisListDriftanalysis` |
| Health | 1 | `healthList` |
| Testing | 1 | `testingCreateMarketplacetest` |

## Known Issues & Edge Cases

### Conflict: Collection vs Item Endpoints

Three operation IDs are assigned to both list and detail endpoints due to ambiguous path structures:

1. **`marketplaceGetMarketplacePluginsKeysById`**
   - `/api/v1/marketplace/plugins/{pluginId}/keys` (LIST)
   - `/api/v1/marketplace/plugins/{pluginId}/keys/{keyId}` (GET)
   - **Resolution**: Rename list operation to `marketplaceListMarketplacePluginsKeys`

2. **`marketplaceGetMarketplacePluginsVersionsById`**
   - `/api/v1/marketplace/plugins/{pluginId}/versions` (LIST)
   - `/api/v1/marketplace/plugins/{pluginId}/versions/{identifier}` (GET)
   - **Resolution**: Rename list operation to `marketplaceListMarketplacePluginsVersions`

3. **`marketplaceGetMarketplacePluginsVersionsArtifactById`**
   - `/api/v1/marketplace/plugins/{pluginId}/versions/{version}/artifact` (LIST)
   - `/api/v1/marketplace/plugins/{pluginId}/versions/{version}/artifact/{artifactId}` (GET)
   - **Resolution**: Rename list operation to `marketplaceListMarketplacePluginsVersionsArtifact`

## Governance Rules

### 1. Uniqueness
- Every operation ID **must be globally unique** across the entire API contract.
- Enforce in CI/CD: fail on duplicate operation IDs.

### 2. Naming Consistency
- All operation IDs **must follow the `{domain}{verb}{resource}` pattern**.
- No abbreviations except standard HTTP verb mappings.
- No special characters except those naturally derived from camelCase.

### 3. Stability
- Once published in a stable API version, operation IDs are immutable.
- Changes to operation IDs are breaking changes.
- Deprecate old IDs before removing them.

### 4. Documentation
- Every operation **must have**:
  - Unique `operationId`
  - `summary` (one-line description)
  - `tags` (domain classification)
  - Clear request/response schemas

## SDK Generation Impact

Operation IDs directly map to SDK method names:

**TypeScript SDK:**
```typescript
client.marketplace.listMarketplaceProducts()
client.marketplace.getMarketplaceProductsById(productId)
client.intelligence.getIntelligenceHealth()
```

**JavaScript SDK:**
```javascript
client.marketplace.listMarketplaceProducts()
client.marketplace.getMarketplaceProductsById(productId)
client.intelligence.getIntelligenceHealth()
```

**PHP SDK:**
```php
$client->marketplace->listMarketplaceProducts()
$client->marketplace->getMarketplaceProductsById($productId)
$client->intelligence->getIntelligenceHealth()
```

## Enforcement Strategy

### Phase 1: Validation
- Audit all operation IDs for uniqueness ✅
- Check naming pattern compliance ✅
- Flag edge cases ✅

### Phase 2: CI Integration
- Add operation ID uniqueness check to pre-commit hook
- Add naming pattern linting to PR checks
- Add breaking-change detection for operation ID changes

### Phase 3: SDK Generation
- Use operation IDs as canonical method names
- Generate SDKs in TypeScript, JavaScript, PHP
- Verify SDK compilation against updated operation IDs

### Phase 4: Compatibility Gating
- Add operation ID changes as breaking changes
- Fail PRs that introduce operation ID conflicts
- Require explicit version bump for operation ID changes

## Manual Review Required

The following endpoints require manual review to fix the 3 edge-case conflicts:

```yaml
# marketplace.yaml
/api/v1/marketplace/plugins/{pluginId}/keys:
  get:
    operationId: marketplaceListMarketplacePluginsKeys  # CHANGE THIS FROM: marketplaceGetMarketplacePluginsKeysById

/api/v1/marketplace/plugins/{pluginId}/versions:
  get:
    operationId: marketplaceListMarketplacePluginsVersions  # CHANGE THIS FROM: marketplaceGetMarketplacePluginsVersionsById

/api/v1/marketplace/plugins/{pluginId}/versions/{version}/artifact:
  get:
    operationId: marketplaceListMarketplacePluginsVersionsArtifact  # CHANGE THIS FROM: marketplaceGetMarketplacePluginsVersionsArtifactById
```

## Next Steps

1. **Fix 3 edge-case conflicts** (manual updates above)
2. **Set up CI validation** (semantic OpenAPI validation)
3. **Enable SDK generation** (TypeScript, JavaScript, PHP)
4. **Add breaking-change detection** (operation IDs as part of contract)
5. **Publish SDK artifacts** (package registries: npm, Packagist, etc.)
