# @gd-workflow-bridge-pro/api-sdk

Auto-generated TypeScript SDK for GD Workflow Bridge Pro API.

## Installation

```bash
npm install @gd-workflow-bridge-pro/api-sdk
```

## Usage

```typescript
import { ApiClient } from '@gd-workflow-bridge-pro/api-sdk';

const client = new ApiClient({
  basePath: 'https://api.example.com',
  accessToken: 'your-bearer-token'
});

// List marketplace products
const products = await client.marketplaceListMarketplaceProducts();

// Create a new product
const created = await client.marketplaceCreateMarketplaceProducts({
  name: 'My Product',
  description: 'Product description'
});
```

## API Version

v1.0.0

## API Operations

Total: 62

- **Stable**: 45 operations
- **Beta**: 16 operations  
- **Experimental**: 1 operations
- **Internal**: 0 operations

## Schemas

Total: 55 types generated

## Generated From

This SDK was auto-generated from the OpenAPI 3.1.0 specification defined in:
- `openapi/openapi.yaml` (root spec)
- `openapi/paths/` (modular endpoint definitions)
- `openapi/schemas/` (modular type definitions)

All operation IDs and type names follow the governance rules defined in `openapi/OPERATION_ID_GOVERNANCE.md`.
