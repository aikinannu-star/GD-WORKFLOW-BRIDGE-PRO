<?php
/**
 * Domains Service
 * Domain registration and DNS management
 */

define('SERVICE_NAME', 'domains');
define('SERVICE_PORT', 8005);

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
// POST /api/v1/domains/search
// POST /api/v1/domains/register
// GET /api/v1/domains/:id
// PUT /api/v1/domains/:id
// DELETE /api/v1/domains/:id
// GET /api/v1/domains/:id/dns
// PUT /api/v1/domains/:id/dns
// POST /api/v1/domains/:id/renew

http_response_code(404);
echo json_encode(['error' => 'not_found']);
