# API & SDK Stabilization — Implementation Roadmap

## Phase 1.1: OpenAPI 3.1 Specification (Week 1-2)

### Objective
Generate machine-readable API specification covering all 20+ endpoints.

### Deliverables

**File:** `openapi/gd-workflow-bridge-pro-api-3.1.yaml`

```yaml
openapi: 3.1.0
info:
  title: GD Workflow Bridge Pro API
  version: 1.0.0
  description: Governance, remediation, and intelligence platform API
  contact:
    email: api@gdworkflow.io
  license:
    name: Apache 2.0

servers:
  - url: http://127.0.0.1:8006
    description: Local development
  - url: https://api.gdworkflow.io
    description: Production

paths:
  /api/v1/intelligence-learning:
    get:
      summary: Get learning insights
      tags: [Intelligence]
      parameters:
        - name: tenant_id
          in: query
          schema: { type: string }
      responses:
        200:
          description: Learning insights
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/LearningInsights'
  
  /api/v1/remediation-events:
    post:
      summary: Record remediation event
      tags: [Remediation]
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/RemediationEvent'
      responses:
        201:
          description: Event recorded
          
components:
  schemas:
    LearningInsights:
      type: object
      required: [recommendations, adoption_gaps, recurring_issues, trends]
      properties:
        recommendations:
          type: array
          items:
            $ref: '#/components/schemas/Recommendation'
        adoption_gaps:
          type: array
          items:
            type: object
        
    Recommendation:
      type: object
      required: [id, title, issue_type]
      properties:
        id:
          type: string
          example: rec-12345
        title:
          type: string
        issue_type:
          type: string
          enum: [efficiency, compliance, security]
    
    RemediationEvent:
      type: object
      required: [remediation_id, status, timestamp]
      properties:
        remediation_id:
          type: string
        status:
          type: string
          enum: [pending, approved, completed, failed]
        timestamp:
          type: string
          format: date-time

  securitySchemes:
    ApiKeyAuth:
      type: apiKey
      in: header
      name: Authorization

security:
  - ApiKeyAuth: []
```

### Implementation Steps

1. **Audit current endpoints** (30 min)
   - List all endpoints in `services/marketplace/server.php`
   - Categorize by feature: Intelligence, Remediation, Marketplace, Governance, Learning

2. **Define request/response schemas** (2 hours)
   - Extract from existing code
   - Example: `/api/v1/intelligence-learning` returns `LearningInsights` object
   - Validate against actual responses

3. **Document authentication** (30 min)
   - API key header format
   - Tenant isolation requirements
   - Rate limiting rules

4. **Validate completeness** (1 hour)
   - Every endpoint must be documented
   - Every response code documented (200, 400, 401, 404, 500)
   - All query/path/body parameters listed

---

## Phase 1.2: Schema Validation in CI (Week 2)

### Objective
Prevent breaking API changes in PRs.

### Files to Create

**File:** `.github/workflows/api-validation.yml`

```yaml
name: API Validation

on:
  pull_request:

jobs:
  openapi-validation:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Validate OpenAPI spec
        run: |
          npm install -g @redocly/cli
          redocly lint openapi/gd-workflow-bridge-pro-api-3.1.yaml
      
      - name: Check for breaking changes
        run: |
          npm install -g @openapitools/openapi-generator-cli
          node scripts/validate-api-compat.js
```

**File:** `scripts/validate-api-compat.js`

```javascript
/**
 * Validate API backward compatibility
 * Checks PR against main branch for breaking changes
 */

const fs = require('fs');
const YAML = require('yaml');

// Load current spec (PR branch)
const currentSpec = YAML.parse(fs.readFileSync('openapi/gd-workflow-bridge-pro-api-3.1.yaml', 'utf8'));

// Load baseline spec (main branch) — would be fetched from Git
const baselineSpec = { /* fetched from main */ };

const breaking = [];

// Check for removed endpoints
for (const path in baselineSpec.paths) {
  if (!currentSpec.paths[path]) {
    breaking.push(`Endpoint removed: ${path}`);
  }
}

// Check for schema changes
for (const [name, schema] of Object.entries(baselineSpec.components.schemas)) {
  const current = currentSpec.components.schemas[name];
  if (!current) {
    breaking.push(`Schema removed: ${name}`);
    continue;
  }
  
  // Required properties cannot be removed
  const baseRequired = schema.required || [];
  const currentRequired = current.required || [];
  
  for (const req of baseRequired) {
    if (!currentRequired.includes(req)) {
      breaking.push(`Required property removed: ${name}.${req}`);
    }
  }
}

if (breaking.length > 0) {
  console.error('Breaking API changes detected:');
  breaking.forEach(b => console.error(`  ❌ ${b}`));
  process.exit(1);
} else {
  console.log('✅ API changes are backward compatible');
}
```

---

## Phase 1.3: SDK Generation (Week 3)

### Objective
Generate production-ready SDKs from OpenAPI spec.

### Implementation

**Tool:** OpenAPI Generator (`@openapitools/openapi-generator-cli`)

**Files to Create**

**File:** `scripts/generate-sdks.sh`

```bash
#!/bin/bash
set -e

SPEC="openapi/gd-workflow-bridge-pro-api-3.1.yaml"
GENERATORS="javascript typescript php"

for gen in $GENERATORS; do
  echo "Generating $gen SDK..."
  openapi-generator-cli generate \
    -i $SPEC \
    -g $gen \
    -o sdks/$gen \
    -c sdks/$gen/.openapi-config.yaml
  
  # Test generated SDK
  cd sdks/$gen
  if [ "$gen" = "javascript" ] || [ "$gen" = "typescript" ]; then
    npm install
    npm test
  elif [ "$gen" = "php" ]; then
    composer install
    composer test
  fi
  cd ../../
done

echo "✅ All SDKs generated and tested"
```

### SDK Directory Structure

```
sdks/
├── javascript/
│   ├── .openapi-config.yaml
│   ├── package.json
│   ├── src/
│   │   ├── apis/
│   │   │   ├── IntelligenceApi.js
│   │   │   ├── RemediationApi.js
│   │   │   └── ...
│   │   └── models/
│   │       ├── LearningInsights.js
│   │       ├── RemediationEvent.js
│   │       └── ...
│   └── test/
│
├── typescript/
│   ├── .openapi-config.yaml
│   ├── tsconfig.json
│   ├── src/
│   │   ├── apis/
│   │   ├── models/
│   │   └── index.ts
│   └── test/
│
└── php/
    ├── .openapi-config.yaml
    ├── composer.json
    ├── src/
    │   ├── Api/
    │   ├── Model/
    │   └── Client.php
    └── test/
```

### Generated SDK Features

Each SDK includes:
- ✅ Full type safety (TypeScript, PHP with type hints)
- ✅ Request/response validation
- ✅ Built-in authentication (API key handling)
- ✅ Error handling and retries
- ✅ Rate limit awareness
- ✅ Comprehensive documentation

---

## Phase 1.4: Backward Compatibility Tests (Week 3)

### Objective
Ensure old clients continue working across versions.

**File:** `tests/compatibility/backward-compat.test.js`

```javascript
const SDK = require('../../sdks/javascript');

describe('Backward Compatibility', () => {
  describe('v1.0.0 Client Against Current API', () => {
    // Test that endpoints from v1.0.0 still exist and work
    
    it('GET /api/v1/intelligence-learning returns expected schema', async () => {
      const client = new SDK.IntelligenceApi();
      const result = await client.getIntelligenceLearning({
        tenant_id: 'test-tenant'
      });
      
      // Must have these fields (v1.0.0 guaranteed them)
      expect(result).toHaveProperty('recommendations');
      expect(result).toHaveProperty('adoption_gaps');
      expect(result).toHaveProperty('recurring_issues');
    });
    
    it('POST /api/v1/remediation-events accepts old event format', async () => {
      const client = new SDK.RemediationApi();
      const event = {
        remediation_id: 'rem-123',
        status: 'completed',
        timestamp: new Date().toISOString()
      };
      
      const response = await client.recordRemediationEvent(event);
      expect(response.status).toBe(201);
    });
  });
});
```

---

## Phase 1.5: Deprecation Policy (Week 4)

### File: `docs/API_DEPRECATION_POLICY.md`

```markdown
# API Deprecation Policy

## Versioning

We follow Semantic Versioning:
- **MAJOR** (2.0.0): Breaking changes
- **MINOR** (1.1.0): New features, backward compatible
- **PATCH** (1.0.1): Bug fixes

## Deprecation Timeline

1. **Announcement** — Deprecation announced in release notes
2. **Support Window** — 6 months of continued support
3. **Sunset Date** — Endpoint removed

Example:
```
v1.0.0: /api/v1/intelligence (deprecated in favor of /api/v2/intelligence)
v1.0.0–1.6.0: Endpoint still functional with 301 redirect
v2.0.0: Endpoint removed entirely
```

## Breaking Changes Require:

- Major version bump
- Migration guide in docs
- Automated deprecation warnings in old SDK
- 6-month notice period

## Requesting Early Removal

Contact api@gdworkflow.io with use case.
```

---

## Phase 1.6: Semantic Versioning + CI Check (Week 4)

### File: `scripts/check-version-bump.js`

```javascript
/**
 * Validate that version bump matches change severity
 */

const fs = require('fs');
const semver = require('semver');

const currentVersion = JSON.parse(
  fs.readFileSync('package.json')
).version;

// In CI, compare against previous version
const previousVersion = process.env.PREVIOUS_VERSION || '1.0.0';

// Determine change severity from openapi spec diff
const severity = detectChangeType(); // breaking | feature | patch

const newVersion = getNewVersion(previousVersion, severity);

if (newVersion !== currentVersion) {
  console.error(`Version mismatch: Expected ${newVersion}, got ${currentVersion}`);
  process.exit(1);
}

console.log(`✅ Version bump correct: ${previousVersion} → ${currentVersion}`);
```

---

## Success Criteria

- [ ] OpenAPI spec documents all 20+ endpoints
- [ ] Spec validates in CI (no breaking changes without review)
- [ ] 3 SDKs generated and published
- [ ] Old clients pass backward compat tests
- [ ] Deprecation policy defined and followed
- [ ] Version bumps checked in CI

## Timeline
**Weeks 1-4 of Sprint 7.2**

## Next Phase
Once APIs stabilized, move to **Production Observability** (Prometheus, OpenTelemetry, structured logging).

---

## Quick Start Commands

```bash
# Generate OpenAPI spec from code
openapi-generator-cli generate -i openapi/spec.yaml -g javascript -o sdks/javascript

# Validate spec
npm run openapi:validate

# Generate all SDKs
bash scripts/generate-sdks.sh

# Run compatibility tests
npm run test:compat

# Check version bump
npm run validate:semver
```
