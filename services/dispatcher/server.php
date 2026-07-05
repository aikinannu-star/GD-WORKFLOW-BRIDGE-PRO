<?php
/**
 * Task Dispatcher Service (MVP)
 * Receives AI engineering tasks and routes them to specialized tools.
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';
require_once __DIR__ . '/ToolRegistry.php';

define('SERVICE_NAME', 'dispatcher');
define('SERVICE_PORT', 8020);

global $method, $uri;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    ServiceHelpers::sendJson(200, ['status' => 'ok', 'service' => SERVICE_NAME, 'version' => '0.1.0', 'time' => gmdate('c')]);
}

// Autoload any tools in the tools directory
foreach (glob(__DIR__ . '/tools/*.php') as $f) {
    require_once $f;
}

$registry = new ToolRegistry();
// Register known tools
if (class_exists('WorkflowTool')) { $registry->register(new WorkflowTool()); }
if (class_exists('ServiceTool')) { $registry->register(new ServiceTool()); }
if (class_exists('ReviewTool')) { $registry->register(new ReviewTool()); }
if (class_exists('ExplainTool')) { $registry->register(new ExplainTool()); }
if (class_exists('RefactorTool')) { $registry->register(new RefactorTool()); }

if ($method === 'POST' && $uri === '/api/v1/dispatcher/task') {
    $input = ServiceHelpers::getRequestBody();
    $type = $input['type'] ?? 'unknown';
    $payload = $input['payload'] ?? [];

    $resp = $registry->dispatch($type, $payload);
    $status = $resp['status'] ?? 500;
    $result = $resp['result'] ?? $resp['result'] ?? null;
    ServiceHelpers::sendJson($status, ['task_type' => $type, 'result' => $result]);
}

// Workflow execution endpoint
if ($method === 'POST' && preg_match('#^/api/v1/workflows/([^/]+)/execute$#', $uri, $m)) {
    $id = $m[1];
    $body = ServiceHelpers::getRequestBody();
    require_once __DIR__ . '/services/WorkflowExecutionService.php';
    $svc = new WorkflowExecutionService();
    try {
        $result = $svc->executeById($id, $body['input'] ?? []);
        ServiceHelpers::sendJson(200, $result);
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if ($msg === 'not_found') { ServiceHelpers::sendJson(404, ['error' => 'not_found']); }
        ServiceHelpers::sendJson(422, ['error' => 'execution_failed', 'detail' => $msg]);
    }
}

// Trigger workflow endpoint
if ($method === 'POST' && preg_match('#^/api/v1/workflows/([^/]+)/trigger$#', $uri, $m)) {
    $id = $m[1];
    $body = ServiceHelpers::getRequestBody();
    require_once __DIR__ . '/services/WorkflowTriggerService.php';
    $svc = new WorkflowTriggerService();
    try {
        $result = $svc->trigger($id, $body['type'] ?? 'manual', $body['context'] ?? []);
        ServiceHelpers::sendJson(200, $result);
    } catch (Exception $e) {
        ServiceHelpers::sendJson(422, ['error' => 'trigger_failed', 'detail' => $e->getMessage()]);
    }
}

// Scheduler endpoints
if ($method === 'POST' && preg_match('#^/api/v1/workflows/([^/]+)/schedule$#', $uri, $m)) {
    $id = $m[1];
    $body = ServiceHelpers::getRequestBody();
    require_once __DIR__ . '/services/SchedulerService.php';
    $svc = new SchedulerService();
    try {
        $schedule = $svc->createSchedule($id, $body);
        ServiceHelpers::sendJson(200, $schedule);
    } catch (Exception $e) {
        ServiceHelpers::sendJson(422, ['error' => 'schedule_failed', 'detail' => $e->getMessage()]);
    }
}

if ($method === 'GET' && preg_match('#^/api/v1/workflows/([^/]+)/schedules$#', $uri, $m)) {
    $id = $m[1];
    require_once __DIR__ . '/services/SchedulerService.php';
    $svc = new SchedulerService();
    $schedules = $svc->listSchedules($id);
    ServiceHelpers::sendJson(200, ['count' => count($schedules), 'schedules' => $schedules]);
}

if ($method === 'DELETE' && preg_match('#^/api/v1/schedules/([^/]+)$#', $uri, $m)) {
    $id = $m[1];
    require_once __DIR__ . '/services/SchedulerService.php';
    $svc = new SchedulerService();
    $deleted = $svc->deleteSchedule($id);
    ServiceHelpers::sendJson($deleted ? 200 : 404, ['deleted' => $deleted]);
}

// Workflows retrieval endpoints
if ($method === 'GET' && preg_match('#^/api/v1/workflows$#', $uri)) {
    require_once __DIR__ . '/repositories/FileWorkflowRepository.php';
    $repo = new FileWorkflowRepository();
    $tenant = $_GET['tenantId'] ?? 'default';
    $status = $_GET['status'] ?? null;
    $list = $repo->listByTenant($tenant);
    if ($status) {
        $list = array_values(array_filter($list, function($r) use ($status) { return isset($r['status']) && $r['status'] === $status; }));
    }
    ServiceHelpers::sendJson(200, ['count' => count($list), 'workflows' => $list]);
}

if ($method === 'GET' && preg_match('#^/api/v1/workflows/([^/]+)$#', $uri, $m)) {
    $id = $m[1];
    require_once __DIR__ . '/repositories/FileWorkflowRepository.php';
    $repo = new FileWorkflowRepository();
    $item = $repo->get($id);
    if ($item === null) {
        ServiceHelpers::sendJson(404, ['error' => 'not_found']);
    }
    ServiceHelpers::sendJson(200, $item);
}

// Update workflow (draft updates)
if ($method === 'PUT' && preg_match('#^/api/v1/workflows/([^/]+)$#', $uri, $m)) {
    $id = $m[1];
    $body = ServiceHelpers::getRequestBody();
    require_once __DIR__ . '/services/WorkflowLifecycleService.php';
    $svc = new WorkflowLifecycleService();
    try {
        $updated = $svc->update($id, $body, $body['updatedBy'] ?? 'api');
        ServiceHelpers::sendJson(200, $updated);
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if ($msg === 'not_found') { ServiceHelpers::sendJson(404, ['error' => 'not_found']); }
        if ($msg === 'cannot_modify_published_or_archived') { ServiceHelpers::sendJson(409, ['error' => 'immutable']); }
        ServiceHelpers::sendJson(400, ['error' => 'update_failed', 'detail' => $msg]);
    }
}

// Publish workflow
if ($method === 'POST' && preg_match('#^/api/v1/workflows/([^/]+)/publish$#', $uri, $m)) {
    $id = $m[1];
    $body = ServiceHelpers::getRequestBody();
    require_once __DIR__ . '/services/WorkflowLifecycleService.php';
    $svc = new WorkflowLifecycleService();
    try {
        $pub = $svc->publish($id, $body['by'] ?? 'api');
        ServiceHelpers::sendJson(200, $pub);
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if ($msg === 'not_found') { ServiceHelpers::sendJson(404, ['error' => 'not_found']); }
        // validation errors encoded as JSON
        ServiceHelpers::sendJson(422, ['error' => 'validation_failed', 'detail' => $msg]);
    }
}

// Archive workflow
if ($method === 'POST' && preg_match('#^/api/v1/workflows/([^/]+)/archive$#', $uri, $m)) {
    $id = $m[1];
    $body = ServiceHelpers::getRequestBody();
    require_once __DIR__ . '/services/WorkflowLifecycleService.php';
    $svc = new WorkflowLifecycleService();
    try {
        $arch = $svc->archive($id, $body['by'] ?? 'api');
        ServiceHelpers::sendJson(200, $arch);
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if ($msg === 'not_found') { ServiceHelpers::sendJson(404, ['error' => 'not_found']); }
        ServiceHelpers::sendJson(400, ['error' => 'archive_failed', 'detail' => $msg]);
    }
}

// Versions
if ($method === 'GET' && preg_match('#^/api/v1/workflows/([^/]+)/versions$#', $uri, $m)) {
    $id = $m[1];
    require_once __DIR__ . '/services/WorkflowLifecycleService.php';
    $svc = new WorkflowLifecycleService();
    try {
        $vers = $svc->versions($id);
        ServiceHelpers::sendJson(200, ['count' => count($vers), 'versions' => $vers]);
    } catch (Exception $e) {
        ServiceHelpers::sendJson(400, ['error' => 'versions_failed', 'detail' => $e->getMessage()]);
    }
}

// Workflow audit events
if ($method === 'GET' && preg_match('#^/api/v1/workflows/([^/]+)/events$#', $uri, $m)) {
    $id = $m[1];
    $limit = isset($_GET['limit']) ? max(1, min(200, intval($_GET['limit']))) : 50;
    $offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;
    $action = $_GET['action'] ?? null;
    $since = $_GET['since'] ?? null;
    $sort = strtolower($_GET['sort'] ?? 'desc');
    require_once __DIR__ . '/services/AuditService.php';
    $audit = new AuditService();
    $events = $audit->listForWorkflow($id);

    if ($action) {
        $events = array_values(array_filter($events, function($e) use ($action) { return ($e['action'] ?? '') === $action; }));
    }
    if ($since) {
        $events = array_values(array_filter($events, function($e) use ($since) { return ($e['timestamp'] ?? '') >= $since; }));
    }
    if ($sort === 'asc') {
        usort($events, function($a, $b) { return strcmp($a['timestamp'] ?? '', $b['timestamp'] ?? ''); });
    } else {
        usort($events, function($a, $b) { return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''); });
    }

    $total = count($events);
    $events = array_slice($events, $offset, $limit);
    ServiceHelpers::sendJson(200, ['workflowId' => $id, 'count' => count($events), 'total' => $total, 'events' => $events]);
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
