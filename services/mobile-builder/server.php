<?php
/**
 * Mobile App Builder Service (MVP)
 * Provides lightweight mobile app project scaffolding, preview, and build metadata.
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';

define('SERVICE_NAME', 'mobile-builder');
define('SERVICE_PORT', 8014);

global $method, $uri;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function loadMobileApps(): array {
    return ServiceHelpers::loadJson('mobile-builder', 'apps.json');
}

function saveMobileApps(array $apps): bool {
    return ServiceHelpers::saveJson('mobile-builder', 'apps.json', $apps);
}

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    ServiceHelpers::sendJson(200, ['status' => 'ok', 'service' => SERVICE_NAME, 'version' => '0.1.0', 'time' => gmdate('c')]);
}

if ($method === 'GET' && $uri === '/api/v1/mobile-builder/apps') {
    ServiceHelpers::sendJson(200, ['apps' => loadMobileApps()]);
}

if ($method === 'POST' && $uri === '/api/v1/mobile-builder/apps') {
    $input = ServiceHelpers::getRequestBody();
    $apps = loadMobileApps();
    $app = [
        'id' => ServiceHelpers::generateUuid(),
        'name' => trim($input['name'] ?? 'New Mobile App'),
        'platforms' => $input['platforms'] ?? ['ios', 'android'],
        'status' => 'draft',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'spec' => $input['spec'] ?? [],
    ];
    $apps[] = $app;
    saveMobileApps($apps);
    ServiceHelpers::sendJson(201, ['app' => $app]);
}

if ($method === 'GET' && preg_match('#^/api/v1/mobile-builder/apps/([^/]+)$#', $uri, $matches)) {
    $appId = $matches[1];
    foreach (loadMobileApps() as $app) {
        if (($app['id'] ?? '') === $appId) {
            ServiceHelpers::sendJson(200, ['app' => $app]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'app_not_found']);
}

if ($method === 'POST' && preg_match('#^/api/v1/mobile-builder/apps/([^/]+)/release$#', $uri, $matches)) {
    $appId = $matches[1];
    $input = ServiceHelpers::getRequestBody();
    $apps = loadMobileApps();
    foreach ($apps as &$app) {
        if (($app['id'] ?? '') === $appId) {
            $app['status'] = 'released';
            $app['release_notes'] = trim($input['release_notes'] ?? 'Initial mobile release');
            $app['released_at'] = gmdate('c');
            $app['updated_at'] = gmdate('c');
            saveMobileApps($apps);
            ServiceHelpers::sendJson(200, ['app' => $app]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'app_not_found']);
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
