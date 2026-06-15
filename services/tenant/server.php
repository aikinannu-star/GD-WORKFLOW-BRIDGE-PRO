<?php
/**
 * Tenant Management Service
 * Manages tenants, branding, feature flags, and white-label configuration.
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';

define('SERVICE_NAME', 'tenant');
define('SERVICE_PORT', 8009);

global $method, $uri;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function loadTenants(): array
{
    return ServiceHelpers::loadJson('tenant', 'tenants.json');
}

function saveTenants(array $tenants): bool
{
    return ServiceHelpers::saveJson('tenant', 'tenants.json', $tenants);
}

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    ServiceHelpers::sendJson(200, [
        'status' => 'ok',
        'service' => SERVICE_NAME,
        'version' => '1.0.0',
        'time' => gmdate('c'),
    ]);
}

if ($method === 'POST' && $uri === '/api/v1/tenants') {
    $input = ServiceHelpers::getRequestBody();
    $name = trim($input['name'] ?? '');
    $domain = trim($input['domain'] ?? '');
    if (!$name || !$domain) {
        ServiceHelpers::sendJson(400, ['error' => 'name and domain are required']);
    }

    $tenants = loadTenants();
    foreach ($tenants as $tenant) {
        if ($tenant['domain'] === $domain) {
            ServiceHelpers::sendJson(409, ['error' => 'domain_already_registered']);
        }
    }

    $tenantId = ServiceHelpers::generateUuid();
    $newTenant = [
        'id' => $tenantId,
        'name' => $name,
        'domain' => $domain,
        'status' => 'active',
        'created_at' => gmdate('c'),
        'branding' => [
            'logo_url' => $input['branding']['logo_url'] ?? null,
            'primary_color' => $input['branding']['primary_color'] ?? '#FFB300',
            'accent_color' => $input['branding']['accent_color'] ?? '#FFFFFF',
        ],
        'settings' => $input['settings'] ?? ['plan' => 'starter', 'currency' => 'USD'],
        'feature_flags' => $input['feature_flags'] ?? ['cms' => true, 'billing' => true, 'analytics' => false],
    ];

    $tenants[] = $newTenant;
    saveTenants($tenants);

    ServiceHelpers::sendJson(201, ['success' => true, 'tenant' => $newTenant]);
}

if ($method === 'GET' && preg_match('#^/api/v1/tenants/([a-f0-9]+)$#', $uri, $matches)) {
    $tenantId = $matches[1];
    $tenants = loadTenants();
    foreach ($tenants as $tenant) {
        if ($tenant['id'] === $tenantId) {
            ServiceHelpers::sendJson(200, ['tenant' => $tenant]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'tenant_not_found']);
}

if ($method === 'PUT' && preg_match('#^/api/v1/tenants/([a-f0-9]+)$#', $uri, $matches)) {
    $tenantId = $matches[1];
    $input = ServiceHelpers::getRequestBody();
    $tenants = loadTenants();
    foreach ($tenants as &$tenant) {
        if ($tenant['id'] === $tenantId) {
            $tenant['name'] = $input['name'] ?? $tenant['name'];
            $tenant['branding'] = array_merge($tenant['branding'], $input['branding'] ?? []);
            $tenant['settings'] = array_merge($tenant['settings'], $input['settings'] ?? []);
            $tenant['feature_flags'] = array_merge($tenant['feature_flags'], $input['feature_flags'] ?? []);
            saveTenants($tenants);
            ServiceHelpers::sendJson(200, ['success' => true, 'tenant' => $tenant]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'tenant_not_found']);
}

if ($method === 'GET' && preg_match('#^/api/v1/tenants/([a-f0-9]+)/settings$#', $uri, $matches)) {
    $tenantId = $matches[1];
    $tenants = loadTenants();
    foreach ($tenants as $tenant) {
        if ($tenant['id'] === $tenantId) {
            ServiceHelpers::sendJson(200, ['settings' => $tenant['settings'], 'branding' => $tenant['branding'], 'feature_flags' => $tenant['feature_flags']]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'tenant_not_found']);
}

if ($method === 'GET' && $uri === '/api/v1/tenants') {
    $tenants = loadTenants();
    ServiceHelpers::sendJson(200, ['tenants' => $tenants]);
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
