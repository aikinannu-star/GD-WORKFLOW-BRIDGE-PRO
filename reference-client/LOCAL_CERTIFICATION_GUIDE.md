# Reference Client: Local Certification Guide

This guide walks through setting up and running the reference client certification suite against a local platform instance.

## Prerequisites

- Node.js 18+ installed
- Marketplace API running (port 8006)
- Auth service running (port 8002)
- Both services configured with test data

## Step 1: Generate Authentication Token

The reference client requires a JWT token to authenticate with the platform.

### Test Credentials

```
Email:    ci@example.com
Password: password123
Tenant:   ci-tenant
Role:     admin
```

### Using curl

```bash
curl -X POST http://localhost:8002/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "ci@example.com",
    "password": "password123",
    "tenant_id": "ci-tenant"
  }'
```

### Expected Response

```json
{
  "success": true,
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJnZHdiLWF1dGgtc2VydmljZSIsInN1YiI6ImM1YjQ1ODY3OWVlZTJkOWJjNDc0YWQwOTlmZjZlMDI0IiwidGVuYW50X2lkIjoiY2ktdGVuYW50IiwiZW1haWwiOiJjaUBleGFtcGxlLmNvbSIsInJvbGUiOiJhZG1pbiIsInBlcm1pc3Npb25zIjpbInJlYWQiLCJ3cml0ZSIsImRlcGxveSJdLCJpYXQiOjE3MjcwNzA3MTAsImV4cCI6MTcyNzA3NDMxMH0.signature...",
  "user": {
    "id": "c5b458679eee2d9bc474ad099ff6e024",
    "email": "ci@example.com",
    "role": "admin",
    "tenant_id": "ci-tenant"
  }
}
```

**Copy the `token` value** from the response (the long string starting with `eyJ...`)

## Step 2: Configure Environment

The easiest way is to use the automated setup script:

### Using the Setup Script (Recommended)

**On macOS/Linux:**
```bash
cd reference-client
bash setup.sh
```

**On Windows (PowerShell):**
```powershell
cd reference-client
powershell -ExecutionPolicy Bypass -File setup.ps1
```

The script will:
1. Check that the auth service is running
2. Log in and generate a JWT token
3. Automatically update your `.env` file

### Manual Configuration (Alternative)

If you prefer to manually set the token:

1. Generate the token (see Step 1)
2. Edit `reference-client/.env`:
   ```bash
   nano .env
   ```
3. Paste the token:
   ```env
   API_BASE_URL=http://localhost:8006
   API_AUTH_METHOD=bearer
   API_TOKEN=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
   TENANT_ID=ci-tenant
   REQUEST_TIMEOUT_MS=30000
   ```
4. Save and close

## Step 3: Install Dependencies

```bash
npm install
```

## Step 4: Run Certification

### Option A: Build + Run Full Suite

```bash
npm run ci:validate
```

This runs:
1. TypeScript compilation (`npm run build`)
2. All workflow tests (`npm run test:workflows`)
3. Generates certification reports

### Option B: Run Tests Only (Skip Build)

```bash
npm run test:workflows
```

### Option C: Run Specific Test File

```bash
npx vitest run --run tests/marketplace-workflow.test.ts
```

## Step 5: Review Certification Results

After the test run completes, review:

- **Test Output**: Console shows pass/skip/fail for each workflow
- **JSON Report**: `consumer-certification.json` — structured results
- **HTML Report**: `consumer-certification.html` — human-readable summary

### Expected Results

When all tests pass:

```
✓ tests/workflow.test.ts (3 tests)
✓ tests/marketplace-workflow.test.ts (1 test)
✓ tests/tenant-workflow.test.ts (1 test)
✓ tests/operations-workflow.test.ts (1 test)
✓ tests/remediation-workflow.test.ts (1 test)
✓ tests/remediation-state.test.ts (1 test)
✓ tests/error-model.test.ts (1 test)
✓ tests/error-injection.test.ts (1 test)

Tests:  10 passed (10)
Duration: 2.4s
```

## Troubleshooting

### "API_TOKEN is required in environment variables"

**Cause**: `.env` file not found or `API_TOKEN` is empty.

**Fix**: 
1. Verify `.env` file exists in `reference-client/`
2. Ensure `API_TOKEN` is filled with a valid JWT

### "401 Unauthorized"

**Cause**: Token is invalid or expired.

**Fix**:
1. Re-run Step 1 to generate a fresh token (JWTs expire after 1 hour)
2. Update `API_TOKEN` in `.env`
3. Re-run tests

### "404 Not Found" on endpoints

**Cause**: API base URL or path mismatch.

**Fix**:
1. Verify marketplace server is running on `http://localhost:8006`
2. Check that endpoints exist: `curl http://localhost:8006/api/v1/health`
3. Review SDK endpoint path mappings in `src/sdk.ts`

### Tests timeout or hang

**Cause**: Slow API response or networking issue.

**Fix**:
1. Increase `REQUEST_TIMEOUT_MS` in `.env` (e.g., `60000`)
2. Test API directly: `curl -H "Authorization: Bearer <TOKEN>" http://localhost:8006/api/v1/marketplace/products`
3. Check service logs for errors

## What Gets Tested

The certification suite validates:

- ✅ **SDK initialization** and configuration
- ✅ **Marketplace operations** — list products, list plugins
- ✅ **Plugin lifecycle** — install, uninstall
- ✅ **Tenant operations** — get tenant health, trends, drift
- ✅ **Operations workflows** — platform overview, effectiveness metrics
- ✅ **Remediation flows** — preview, execute, poll health improvement
- ✅ **Intelligence endpoints** — health scores, recommendations
- ✅ **Error handling** — consistent error responses and retry logic

## Next Steps

If all tests pass, the reference client demonstrates:

1. **SDK is consumer-ready** — external developers can use it without patches
2. **API contract is stable** — endpoints and field names are consistent
3. **Developer experience is smooth** — authentication, error handling, async workflows all work

This qualifies as **Sprint 7.2 certification complete**.

If tests fail, refer to the error messages and review `consumer-certification.json` for details.
