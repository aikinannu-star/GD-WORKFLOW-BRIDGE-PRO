<?php
/**
 * Admin Service
 * Admin operations, audit logging, user management
 */

define('SERVICE_NAME', 'admin');
define('SERVICE_PORT', 8008);

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'service' => SERVICE_NAME,
        'version' => '1.0.0',
        'time' => gmdate('c'),
    ]);
    exit;
}

// TODO: Implement endpoints
// GET /api/v1/admin/users
// PUT /api/v1/admin/users/:id
// DELETE /api/v1/admin/users/:id
// GET /api/v1/admin/audit
// GET /api/v1/admin/analytics
// POST /api/v1/admin/support/tickets

http_response_code(404);
echo json_encode(['error' => 'not_found']);
