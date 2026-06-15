<?php
/**
 * Usage Service
 * API call tracking, metering, and limit enforcement.
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';

define('SERVICE_NAME', 'usage');
define('SERVICE_PORT', 8007);

global $method, $uri;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function loadUsage(): array
{
    return ServiceHelpers::loadJson('usage', 'usage.json');
}

function saveUsage(array $usage): bool
{
    return ServiceHelpers::saveJson('usage', 'usage.json', $usage);
}

function loadLimits(): array
{
    return ServiceHelpers::loadJson('usage', 'limits.json');
}

function saveLimits(array $limits): bool
{
    return ServiceHelpers::saveJson('usage', 'limits.json', $limits);
}

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    ServiceHelpers::sendJson(200, [
        'status' => 'ok',
        'service' => SERVICE_NAME,
        'version' => '1.0.0',
        'time' => gmdate('c'),
    ]);
}

if ($method === 'POST' && $uri === '/api/v1/usage/track') {
    $input = ServiceHelpers::getRequestBody();
    $tenantId = $input['tenant_id'] ?? null;
    $event = $input['event'] ?? null;
    $count = (int)($input['count'] ?? 1);

    if (!$tenantId || !$event) {
        ServiceHelpers::sendJson(400, ['error' => 'tenant_id and event are required']);
    }

    $usage = loadUsage();
    $recordId = ServiceHelpers::generateUuid();
    $entry = [
        'id' => $recordId,
        'tenant_id' => $tenantId,
        'event' => $event,
        'count' => $count,
        'recorded_at' => gmdate('c'),
    ];
    $usage[] = $entry;
    saveUsage($usage);
    ServiceHelpers::sendJson(201, ['usage' => $entry]);
}

if ($method === 'GET' && $uri === '/api/v1/usage/summary') {
    $tenantId = $_GET['tenant_id'] ?? null;
    if (!$tenantId) {
        ServiceHelpers::sendJson(400, ['error' => 'tenant_id required']);
    }
    $usage = loadUsage();
    $summary = [];
    foreach ($usage as $entry) {
        if ($entry['tenant_id'] !== $tenantId) {
            continue;
        }
        $summary[$entry['event']] = ($summary[$entry['event']] ?? 0) + $entry['count'];
    }
    ServiceHelpers::sendJson(200, ['tenant_id' => $tenantId, 'summary' => $summary]);
}

if ($method === 'GET' && $uri === '/api/v1/usage/history') {
    $tenantId = $_GET['tenant_id'] ?? null;
    if (!$tenantId) {
        ServiceHelpers::sendJson(400, ['error' => 'tenant_id required']);
    }
    $usage = loadUsage();
    $history = array_values(array_filter($usage, fn($entry) => $entry['tenant_id'] === $tenantId));
    ServiceHelpers::sendJson(200, ['history' => $history]);
}

if ($method === 'GET' && $uri === '/api/v1/usage/limits') {
    $tenantId = $_GET['tenant_id'] ?? null;
    if (!$tenantId) {
        ServiceHelpers::sendJson(400, ['error' => 'tenant_id required']);
    }
    $limits = loadLimits();
    $tenantLimits = $limits[$tenantId] ?? ['api_calls_per_day' => 10000, 'workflows' => 20, 'storage_gb' => 5];
    ServiceHelpers::sendJson(200, ['tenant_id' => $tenantId, 'limits' => $tenantLimits]);
}

if ($method === 'POST' && $uri === '/api/v1/usage/limits') {
    $input = ServiceHelpers::getRequestBody();
    $tenantId = $input['tenant_id'] ?? null;
    if (!$tenantId) {
        ServiceHelpers::sendJson(400, ['error' => 'tenant_id required']);
    }
    $limits = loadLimits();
    $limits[$tenantId] = array_merge($limits[$tenantId] ?? [], $input['limits'] ?? []);
    saveLimits($limits);
    ServiceHelpers::sendJson(200, ['tenant_id' => $tenantId, 'limits' => $limits[$tenantId]]);
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
