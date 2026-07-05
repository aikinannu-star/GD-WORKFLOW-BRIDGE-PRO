# GD Module SDK v1 (JavaScript)

Lightweight JavaScript SDK for interacting with core GD Workflow Bridge services.

Quickstart

1. Run local services (examples):

```bash
# start PHP services (example)
php -S 127.0.0.1:8002 -t services/auth &
php -S 127.0.0.1:8003 -t services/billing &
php -S 127.0.0.1:8004 -t services/cms &
php -S 127.0.0.1:8080 -t services/control-plane &
node services/realtime/server.js &
```

2. Run the SDK smoke test:

```bash
node sdk/javascript/gd-module-sdk/test/test.js
```

Files

- `src/httpClient.js` — minimal HTTP helper using Node core `http/https`.
- `src/gdClient.js` — `GDClient` wrapper with simple methods: `getHealth`, `listMarketplaceProducts`, `evaluatePolicy`, `trackUsage`.
- `test/test.js` — smoke test that calls service health endpoints and a control-plane evaluate call.

Next steps

- Expand `GDClient` with auth/billing/cms convenience methods and token flows.
- Add TypeScript types and publish to npm when ready.

Tenant SDK examples

The SDK includes tenant management helpers (`createTenant`, `getTenant`, `updateTenant`, `getTenantSettings`, `listTenants`). Example usage is provided in `examples/tenant-usage.js`.

Run the tenant dev server and the example:

```bash
# start tenant service (dev)
php -S 127.0.0.1:8009 -t services/tenant services/tenant/server.php

# run the example
node sdk/javascript/gd-module-sdk/examples/tenant-usage.js
```

Marketplace / Module Ecosystem

The SDK now supports plugin versioning and install flows. Example scripts demonstrate registering a plugin, adding a version, installing to a tenant, listing installs, and uninstalling.

Run the marketplace + tenant servers, then run the plugin flow example:

```bash
php -S 127.0.0.1:8006 -t services/marketplace services/marketplace/server.php
php -S 127.0.0.1:8009 -t services/tenant services/tenant/server.php
node sdk/javascript/gd-module-sdk/examples/plugin-flow.js
```

SDK helpers available:

- `listPluginVersions(pluginId)`
- `addPluginVersion(pluginId, versionMeta)`
- `installPlugin(pluginId, tenantId, version?)`
- `uninstallPlugin(pluginId, tenantId)`
- `listPluginInstalls(pluginId, tenantId?)`

