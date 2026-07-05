# GD Module SDK v1 (TypeScript)

This is a TypeScript-first SDK for GD Workflow Bridge. Build and test locally with:

```bash
cd sdk/typescript/gd-module-sdk
npm install
npm run build
npm test
```

Or run in dev mode with `ts-node`:

```bash
npx ts-node src/test/unit.ts
```

The SDK mirrors the JS client surface and provides typed interfaces for core services.

Tenant SDK examples

The TypeScript SDK exposes the same tenant helpers as JS. See `examples/tenant-usage.ts` for a runnable example.

Run the tenant server and the TypeScript example with:

```bash
# start tenant service (dev)
php -S 127.0.0.1:8009 -t services/tenant services/tenant/server.php

# run the example with ts-node
npx ts-node sdk/typescript/gd-module-sdk/examples/tenant-usage.ts
```

Marketplace / Module Ecosystem

The TypeScript SDK supports plugin versioning and install flows. Use `examples/plugin-flow.ts` to exercise registration, version creation, install, list, and uninstall flows.

Run the marketplace + tenant servers, then run the TypeScript example:

```bash
php -S 127.0.0.1:8006 -t services/marketplace services/marketplace/server.php
php -S 127.0.0.1:8009 -t services/tenant services/tenant/server.php
npx ts-node sdk/typescript/gd-module-sdk/examples/plugin-flow.ts
```

SDK helpers available (TS):

- `listPluginVersions(pluginId)`
- `addPluginVersion(pluginId, versionMeta)`
- `installPlugin(pluginId, tenantId, version?)`
- `uninstallPlugin(pluginId, tenantId)`
- `listPluginInstalls(pluginId, tenantId?)`

