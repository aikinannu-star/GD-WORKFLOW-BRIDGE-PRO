<?php
require_once __DIR__ . '/../../services/lib/ServiceHelpers.php';
require_once __DIR__ . '/../../services/lib/AccessGraph.php';
require_once __DIR__ . '/../../services/lib/AccessGraphMiddleware.php';

function fail(string $msg): void {
    echo "FAIL: $msg\n";
    exit(1);
}

function ok(string $msg): void {
    echo "PASS: $msg\n";
}

// Start with clean graph
ServiceHelpers::saveJson('cms', 'graph.json', ['nodes' => [], 'edges' => []]);

$projectId = 'p2';
$userId = 'u2';

$projectNodeId = AccessGraph::nodeId('project', $projectId);
$userNodeId = AccessGraph::nodeId('user', $userId);

AccessGraph::addNode(['id' => $projectNodeId, 'type' => 'project', 'data' => []]);
AccessGraph::addNode(['id' => $userNodeId, 'type' => 'user', 'data' => []]);
AccessGraph::addEdge(['id' => 'edge:' . bin2hex(random_bytes(8)), 'type' => 'CREATED_BY', 'from' => $projectNodeId, 'to' => $userNodeId]);

// Simulate headers for the request
$_SERVER['HTTP_X_USER_ID'] = $userId;
$_SERVER['HTTP_X_USER_ROLES'] = '';
$_SERVER['HTTP_X_TENANT_ID'] = '';

// Should be authorized to read
if (!AccessGraphMiddleware::authorizeFromHeaders($projectId, 'read')) {
    fail('authorizeFromHeaders should allow project owner to read');
}
ok('authorizeFromHeaders allows project owner');

// Admin role via roles header should authorize any user
$_SERVER['HTTP_X_USER_ID'] = 'someone_else';
$_SERVER['HTTP_X_USER_ROLES'] = 'admin';
if (!AccessGraphMiddleware::authorizeFromHeaders($projectId, 'read')) {
    fail('admin role should be authorized');
}
ok('admin role authorized');

// Missing user header should deny
unset($_SERVER['HTTP_X_USER_ID']);
$_SERVER['HTTP_X_USER_ROLES'] = '';
if (AccessGraphMiddleware::authorizeFromHeaders($projectId, 'read')) {
    fail('missing user header should not authorize');
}
ok('missing user header denied');

// Cleanup
@unlink(ServiceHelpers::dataPath('cms', 'graph.json'));

echo "All middleware tests passed\n";
exit(0);
