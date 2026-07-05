<?php
/**
 * Website Builder Service (MVP)
 * Provides lightweight site/project scaffolding and publish workflows.
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';

define('SERVICE_NAME', 'website-builder');
define('SERVICE_PORT', 8013);

global $method, $uri;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function loadWebsiteProjects(): array {
    return ServiceHelpers::loadJson('website-builder', 'projects.json');
}

function saveWebsiteProjects(array $projects): bool {
    return ServiceHelpers::saveJson('website-builder', 'projects.json', $projects);
}

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    ServiceHelpers::sendJson(200, ['status' => 'ok', 'service' => SERVICE_NAME, 'version' => '0.1.0', 'time' => gmdate('c')]);
}

if ($method === 'GET' && $uri === '/api/v1/website-builder/projects') {
    ServiceHelpers::sendJson(200, ['projects' => loadWebsiteProjects()]);
}

if ($method === 'POST' && $uri === '/api/v1/website-builder/projects') {
    $input = ServiceHelpers::getRequestBody();
    $projects = loadWebsiteProjects();
    $project = [
        'id' => ServiceHelpers::generateUuid(),
        'name' => trim($input['name'] ?? 'New Website Project'),
        'owner' => trim($input['owner'] ?? 'unknown'),
        'status' => 'draft',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'settings' => $input['settings'] ?? [],
    ];
    $projects[] = $project;
    saveWebsiteProjects($projects);
    ServiceHelpers::sendJson(201, ['project' => $project]);
}

if ($method === 'GET' && preg_match('#^/api/v1/website-builder/projects/([^/]+)$#', $uri, $matches)) {
    $projectId = $matches[1];
    $projects = loadWebsiteProjects();
    foreach ($projects as $project) {
        if (($project['id'] ?? '') === $projectId) {
            ServiceHelpers::sendJson(200, ['project' => $project]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
}

if ($method === 'POST' && preg_match('#^/api/v1/website-builder/projects/([^/]+)/publish$#', $uri, $matches)) {
    $projectId = $matches[1];
    $projects = loadWebsiteProjects();
    foreach ($projects as &$project) {
        if (($project['id'] ?? '') === $projectId) {
            $project['status'] = 'published';
            $project['published_at'] = gmdate('c');
            $project['updated_at'] = gmdate('c');
            saveWebsiteProjects($projects);
            ServiceHelpers::sendJson(200, ['project' => $project]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
