<?php
/**
 * Analytics Service (MVP)
 * Provides lightweight event ingestion and summary reporting.
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';

define('SERVICE_NAME', 'analytics');
define('SERVICE_PORT', 8018);

global $method, $uri;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function loadAnalyticsEvents(): array {
    return ServiceHelpers::loadJson('analytics', 'events.json');
}

function saveAnalyticsEvents(array $events): bool {
    return ServiceHelpers::saveJson('analytics', 'events.json', $events);
}

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    ServiceHelpers::sendJson(200, ['status' => 'ok', 'service' => SERVICE_NAME, 'version' => '0.1.0', 'time' => gmdate('c')]);
}

if ($method === 'POST' && $uri === '/api/v1/analytics/events') {
    $input = ServiceHelpers::getRequestBody();
    $events = loadAnalyticsEvents();
    $event = [
        'id' => ServiceHelpers::generateUuid(),
        'type' => trim($input['type'] ?? 'event'),
        'tenant_id' => trim($input['tenant_id'] ?? 'default'),
        'payload' => $input['payload'] ?? [],
        'created_at' => gmdate('c'),
    ];
    $events[] = $event;
    saveAnalyticsEvents($events);
    ServiceHelpers::sendJson(201, ['event' => $event]);
}

if ($method === 'GET' && $uri === '/api/v1/analytics/summary') {
    $events = loadAnalyticsEvents();
    $summary = [
        'total_events' => count($events),
        'last_event_at' => count($events) ? end($events)['created_at'] : null,
    ];
    ServiceHelpers::sendJson(200, ['summary' => $summary]);
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
