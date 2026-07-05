# SDK Generation & Distribution Plan

## Overview
This document outlines the strategy for generating production-ready SDKs from the OpenAPI contract, distributing them, and maintaining compatibility across client implementations.

## Phase 1.3: SDK Generation (Target: Week 1-2)

### Architecture
```
openapi.yaml (Canonical Contract)
        ↓
openapi-generator (OpenAPI Generator CLI)
        ↓
    ┌───┴────┬────────┐
    ↓        ↓        ↓
  TypeScript JavaScript PHP
    ↓        ↓        ↓
npm Package  npm Package  Composer Package
```

### Supported Languages & Targets

#### 1. TypeScript SDK
- **Package**: `@gd-workflow-bridge-pro/api-sdk`
- **Registry**: npm (@scope)
- **Features**:
  - Full type safety
  - Async/await support
  - Request/response validation
  - Built-in error handling
- **Generated**: `openapi-generator generate -g typescript-axios`

#### 2. JavaScript SDK
- **Package**: `@gd-workflow-bridge-pro/api-sdk-js`
- **Registry**: npm (@scope)
- **Features**:
  - ES6+ compatible
  - Node.js and browser support
  - Axios-based HTTP client
  - Dynamic validation with zod
- **Generated**: `openapi-generator generate -g javascript`

#### 3. PHP SDK
- **Package**: `gd-workflow-bridge-pro/api-sdk`
- **Registry**: Packagist
- **Features**:
  - PSR-4 autoloading
  - Laravel/Symfony compatible
  - Exception handling
  - HTTP client abstraction
- **Generated**: `openapi-generator generate -g php`

### Generation Parameters

#### Common Configuration
```yaml
apiPackage: Api
modelPackage: Model
packageName: GdWorkflowBridgePro
packageVersion: 1.0.0
```

#### TypeScript Specific
```yaml
packageName: "@gd-workflow-bridge-pro/api-sdk"
npmName: "@gd-workflow-bridge-pro/api-sdk"
npmRepository: "https://registry.npmjs.org"
typescriptThreePlus: true
```

#### JavaScript Specific
```yaml
packageName: "@gd-workflow-bridge-pro/api-sdk-js"
npmName: "@gd-workflow-bridge-pro/api-sdk-js"
npmRepository: "https://registry.npmjs.org"
```

#### PHP Specific
```yaml
composerVendorName: "gd-workflow-bridge-pro"
composerProjectName: "api-sdk"
packagePath: "src"
```

## Implementation Steps

### Step 1: Install OpenAPI Generator
```bash
# Option 1: Docker-based (recommended)
docker run --rm -v "${PWD}:/local" openapitools/openapi-generator-cli generate \
  -i /local/openapi/openapi.yaml \
  -g typescript-axios \
  -o /local/build/sdk/typescript

# Option 2: macOS via Homebrew
brew install openapi-generator

# Option 3: npm (CLI)
npm install @openapitools/openapi-generator-cli -g
```

### Step 2: Generate SDKs
```bash
# TypeScript
openapi-generator generate \
  -i openapi/openapi.yaml \
  -g typescript-axios \
  -o sdk-typescript \
  -c openapi/generator-config-typescript.json

# JavaScript
openapi-generator generate \
  -i openapi/openapi.yaml \
  -g javascript \
  -o sdk-javascript \
  -c openapi/generator-config-javascript.json

# PHP
openapi-generator generate \
  -i openapi/openapi.yaml \
  -g php \
  -o sdk-php \
  -c openapi/generator-config-php.json
```

### Step 3: SDK Compilation & Testing
```bash
# TypeScript
cd sdk-typescript && npm install && npm run build && npm run test

# JavaScript
cd sdk-javascript && npm install && npm run build

# PHP
cd sdk-php && composer install
```

### Step 4: Publish SDKs

#### npm (TypeScript & JavaScript)
```bash
# Setup: create .npmrc with token
npm publish --access public

# Tagging
npm dist-tag add @gd-workflow-bridge-pro/api-sdk@1.0.0 latest
npm dist-tag add @gd-workflow-bridge-pro/api-sdk@1.0.0-beta canary
```

#### Composer (PHP)
```bash
# Uses git tags and Packagist registration
# Tag: v1.0.0
# Packagist: https://packagist.org/packages/gd-workflow-bridge-pro/api-sdk
```

## SDK Usage Examples

### TypeScript
```typescript
import { MarketplaceApi } from '@gd-workflow-bridge-pro/api-sdk';

const api = new MarketplaceApi({
  basePath: 'https://api.example.com',
  accessToken: 'your-bearer-token'
});

// List products
const products = await api.marketplaceListMarketplaceProducts({ tenant_id: 'tenant-1' });

// Create product
const created = await api.marketplaceCreateMarketplaceProducts({
  productCreateRequest: {
    name: 'My Product',
    description: 'Product description'
  }
});
```

### JavaScript
```javascript
const { MarketplaceApi } = require('@gd-workflow-bridge-pro/api-sdk-js');

const api = new MarketplaceApi();
api.basePath = 'https://api.example.com';

// List products
api.marketplaceListMarketplaceProducts({ tenant_id: 'tenant-1' })
  .then(response => console.log(response))
  .catch(error => console.error(error));
```

### PHP
```php
<?php
use GdWorkflowBridgePro\Api\MarketplaceApi;
use GdWorkflowBridgePro\Configuration;

$config = Configuration::getDefaultConfiguration()
    ->setHost('https://api.example.com')
    ->setAccessToken('your-bearer-token');

$api = new MarketplaceApi(null, $config);

// List products
$products = $api->marketplaceListMarketplaceProducts(['tenant_id' => 'tenant-1']);

// Create product
$created = $api->marketplaceCreateMarketplaceProducts(
    new ProductCreateRequest(['name' => 'My Product'])
);
```

## CI/CD Integration

### GitHub Actions Workflow

```yaml
- name: Generate TypeScript SDK
  run: |
    docker run --rm -v "${PWD}:/local" openapitools/openapi-generator-cli generate \
      -i /local/openapi/openapi.yaml \
      -g typescript-axios \
      -o /local/build/sdk-typescript

- name: Compile TypeScript SDK
  run: |
    cd build/sdk-typescript
    npm install
    npm run build

- name: Test TypeScript SDK
  run: |
    cd build/sdk-typescript
    npm run test

- name: Publish to npm
  if: github.event_name == 'release'
  run: |
    cd build/sdk-typescript
    npm publish --access public
```

## Version Management

### Semantic Versioning
- **Major**: Breaking changes to API contract or SDK structure
- **Minor**: New operations, new schemas, backward-compatible changes
- **Patch**: Bug fixes, documentation updates

### Version Alignment
All three SDKs (TypeScript, JavaScript, PHP) **must use identical version numbers** to simplify dependency management and documentation.

### Breaking Change Detection
```python
# Detects:
- Removed paths/operations
- Changed operationIds
- Changed request/response schemas
- Changed HTTP methods
- Removed required parameters
```

## Maintenance & Support

### Update Frequency
1. **Automatic** (CI/CD): Generate on every OpenAPI spec change
2. **Stable**: Publish to package registries on tagged releases
3. **Canary**: Pre-release versions for testing (optional)

### Backward Compatibility
- Maintain at least 2 major versions with security fixes
- Use deprecation notices in SDK documentation for planned removals
- Provide migration guides for breaking changes

### Quality Gates
- ✅ OpenAPI spec validation passes
- ✅ All SDKs compile without errors
- ✅ SDK smoke tests pass (basic operation invocation)
- ✅ No breaking changes detected (unless major version bump)
- ✅ Code coverage maintained >80% (if applicable)
- ✅ Documentation generated and published

## Configuration Files

### `openapi/generator-config-typescript.json`
```json
{
  "packageName": "@gd-workflow-bridge-pro/api-sdk",
  "npmName": "@gd-workflow-bridge-pro/api-sdk",
  "packageVersion": "1.0.0",
  "apiDocumentationUrl": "https://docs.example.com/api",
  "supportsES6": true,
  "npmRepository": "https://registry.npmjs.org"
}
```

### `openapi/generator-config-javascript.json`
```json
{
  "packageName": "@gd-workflow-bridge-pro/api-sdk-js",
  "npmName": "@gd-workflow-bridge-pro/api-sdk-js",
  "packageVersion": "1.0.0",
  "apiDocumentationUrl": "https://docs.example.com/api"
}
```

### `openapi/generator-config-php.json`
```json
{
  "composerVendorName": "gd-workflow-bridge-pro",
  "composerProjectName": "api-sdk",
  "packagePath": "src",
  "namespace": "GdWorkflowBridgePro",
  "packageVersion": "1.0.0"
}
```

## Testing & Validation

### Smoke Tests (Every SDK)
```
✓ Client initialization
✓ Authentication configuration
✓ List operation (read)
✓ Create operation (write)
✓ Get by ID operation
✓ Delete operation
✓ Error handling (401, 404, 500)
✓ Request timeout handling
```

### Integration Tests
- SDK methods map correctly to OpenAPI operationIds
- Request parameters match schema definitions
- Response types match schema definitions
- Error responses are properly typed

## Success Metrics

| Metric | Target |
|--------|--------|
| SDK generation success rate | 100% |
| SDK compilation success rate | 100% |
| Smoke test pass rate | 100% |
| Time to generate all SDKs | < 5 minutes |
| npm package downloads (monthly) | > 100 (by month 3) |
| PHP Composer installs (monthly) | > 50 (by month 3) |
| SDK documentation coverage | 100% |
| Developer satisfaction | > 4.5/5 |

## Timeline

| Phase | Duration | Deliverables |
|-------|----------|--------------|
| Phase 1.3a: Setup | Week 1 | openapi-generator config, CI pipeline |
| Phase 1.3b: Generation | Week 1-2 | TypeScript, JavaScript, PHP SDKs |
| Phase 1.3c: Testing | Week 2 | SDK smoke tests, integration tests |
| Phase 1.3d: Publishing | Week 2-3 | npm packages, Composer package |
| Phase 1.3e: Documentation | Week 3 | Usage guides, API documentation |

## Next: Phase 1.4 — Operation ID Governance ✓
Phase 1.4 has been completed. All 62 operations have unique, consistently named operation IDs that will serve as the canonical method names for the generated SDKs.

## Next: Phase 1.5 — Compatibility Testing
Automated breaking-change detection will ensure SDK compatibility across versions.
