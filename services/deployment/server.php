<?php
/**
 * Deployment Service (MVP)
 * Provides lightweight deployment orchestration metadata and service status.
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';

define('SERVICE_NAME', 'deployment');
define('SERVICE_PORT', 8019);

global $method, $uri;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function loadDeploymentRecords(): array {
    return ServiceHelpers::loadJson('deployment', 'deployments.json');
}

function saveDeploymentRecords(array $records): bool {
    return ServiceHelpers::saveJson('deployment', 'deployments.json', $records);
}

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    ServiceHelpers::sendJson(200, ['status' => 'ok', 'service' => SERVICE_NAME, 'version' => '0.1.0', 'time' => gmdate('c')]);
}

if ($method === 'GET' && $uri === '/api/v1/deployment/services') {
    ServiceHelpers::sendJson(200, ['services' => [
        ['name' => 'gateway', 'status' => 'ok'],
        ['name' => 'auth', 'status' => 'ok'],
        ['name' => 'cms', 'status' => 'ok'],
    ]]);
}

if ($method === 'POST' && $uri === '/api/v1/deployment/deploy') {
    $input = ServiceHelpers::getRequestBody();
    $records = loadDeploymentRecords();
    $record = [
        'id' => ServiceHelpers::generateUuid(),
        'target' => trim($input['target'] ?? 'app'),
        'environment' => trim($input['environment'] ?? 'staging'),
        'status' => 'scheduled',
        'created_at' => gmdate('c'),
    ];
    $records[] = $record;
    saveDeploymentRecords($records);
    ServiceHelpers::sendJson(201, ['deployment' => $record]);
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
