<?php
/**
 * Workflow Automation Service (MVP)
 * Provides workflow definitions, execution, and task status tracking.
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';

define('SERVICE_NAME', 'workflow');
define('SERVICE_PORT', 8016);

global $method, $uri;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function loadWorkflows(): array {
    return ServiceHelpers::loadJson('workflow', 'workflows.json');
}

function saveWorkflows(array $workflows): bool {
    return ServiceHelpers::saveJson('workflow', 'workflows.json', $workflows);
}

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    ServiceHelpers::sendJson(200, ['status' => 'ok', 'service' => SERVICE_NAME, 'version' => '0.1.0', 'time' => gmdate('c')]);
}

if ($method === 'GET' && $uri === '/api/v1/workflow/flows') {
    ServiceHelpers::sendJson(200, ['flows' => loadWorkflows()]);
}

if ($method === 'POST' && $uri === '/api/v1/workflow/flows') {
    $input = ServiceHelpers::getRequestBody();
    $workflows = loadWorkflows();
    $flow = [
        'id' => ServiceHelpers::generateUuid(),
        'name' => trim($input['name'] ?? 'New Workflow'),
        'steps' => $input['steps'] ?? [],
        'status' => 'inactive',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];
    $workflows[] = $flow;
    saveWorkflows($workflows);
    ServiceHelpers::sendJson(201, ['flow' => $flow]);
}

if ($method === 'POST' && preg_match('#^/api/v1/workflow/flows/([^/]+)/execute$#', $uri, $matches)) {
    $flowId = $matches[1];
    $flows = loadWorkflows();
    foreach ($flows as $flow) {
        if (($flow['id'] ?? '') === $flowId) {
            ServiceHelpers::sendJson(200, ['execution' => [
                'flow_id' => $flowId,
                'status' => 'started',
                'started_at' => gmdate('c'),
                'result' => 'Workflow execution simulated.',
            ]]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'flow_not_found']);
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
