<?php
/**
 * Desktop App Builder Service (MVP)
 * Provides lightweight desktop app project scaffolding and packaging metadata.
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';

define('SERVICE_NAME', 'desktop-builder');
define('SERVICE_PORT', 8015);

global $method, $uri;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function loadDesktopApps(): array {
    return ServiceHelpers::loadJson('desktop-builder', 'apps.json');
}

function saveDesktopApps(array $apps): bool {
    return ServiceHelpers::saveJson('desktop-builder', 'apps.json', $apps);
}

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    ServiceHelpers::sendJson(200, ['status' => 'ok', 'service' => SERVICE_NAME, 'version' => '0.1.0', 'time' => gmdate('c')]);
}

if ($method === 'GET' && $uri === '/api/v1/desktop-builder/apps') {
    ServiceHelpers::sendJson(200, ['apps' => loadDesktopApps()]);
}

if ($method === 'POST' && $uri === '/api/v1/desktop-builder/apps') {
    $input = ServiceHelpers::getRequestBody();
    $apps = loadDesktopApps();
    $app = [
        'id' => ServiceHelpers::generateUuid(),
        'name' => trim($input['name'] ?? 'New Desktop App'),
        'platforms' => $input['platforms'] ?? ['windows', 'macos', 'linux'],
        'status' => 'draft',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'metadata' => $input['metadata'] ?? [],
    ];
    $apps[] = $app;
    saveDesktopApps($apps);
    ServiceHelpers::sendJson(201, ['app' => $app]);
}

if ($method === 'GET' && preg_match('#^/api/v1/desktop-builder/apps/([^/]+)$#', $uri, $matches)) {
    $appId = $matches[1];
    foreach (loadDesktopApps() as $app) {
        if (($app['id'] ?? '') === $appId) {
            ServiceHelpers::sendJson(200, ['app' => $app]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'app_not_found']);
}

if ($method === 'POST' && preg_match('#^/api/v1/desktop-builder/apps/([^/]+)/package$#', $uri, $matches)) {
    $appId = $matches[1];
    $apps = loadDesktopApps();
    foreach ($apps as &$app) {
        if (($app['id'] ?? '') === $appId) {
            $app['status'] = 'packaged';
            $app['package_at'] = gmdate('c');
            $app['updated_at'] = gmdate('c');
            saveDesktopApps($apps);
            ServiceHelpers::sendJson(200, ['app' => $app]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'app_not_found']);
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
