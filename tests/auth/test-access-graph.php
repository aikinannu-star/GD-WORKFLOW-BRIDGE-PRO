<?php
require_once __DIR__ . '/../../services/lib/ServiceHelpers.php';
require_once __DIR__ . '/../../services/lib/AccessGraph.php';
require_once __DIR__ . '/../../services/lib/PermissionService.php';

function fail(string $msg): void {
    echo "FAIL: $msg\n";
    exit(1);
}

function ok(string $msg): void {
    echo "PASS: $msg\n";
}

// Start with a clean graph
ServiceHelpers::saveJson('cms', 'graph.json', ['nodes' => [], 'edges' => []]);

$projectId = 'p1';
$userId = 'u1';
$tenantId = 't1';
$orderId = 'o1';

$projectNodeId = AccessGraph::nodeId('project', $projectId);
$userNodeId = AccessGraph::nodeId('user', $userId);
$tenantNodeId = AccessGraph::nodeId('tenant', $tenantId);
$orderNodeId = AccessGraph::nodeId('order', $orderId);

// create nodes
if (!AccessGraph::addNode(['id' => $projectNodeId, 'type' => 'project', 'data' => ['tenant_id' => $tenantNodeId]])) {
    fail('add project node');
}
if (!AccessGraph::addNode(['id' => $userNodeId, 'type' => 'user', 'data' => ['name' => 'Test User']])) {
    fail('add user node');
}

// CREATED_BY -> project_owner
$edgeId = 'edge:' . bin2hex(random_bytes(8));
if (!AccessGraph::addEdge(['id' => $edgeId, 'type' => 'CREATED_BY', 'from' => $projectNodeId, 'to' => $userNodeId])) {
    fail('add CREATED_BY edge');
}

$roles = AccessGraph::resolveProjectRoles($userId, $projectId, $tenantId, false);
if (!in_array('project_owner', $roles, true)) {
    fail('expected project_owner role after CREATED_BY');
}
ok('project_owner inferred');

// project_owner should be allowed project.read
if (!AccessGraph::canUserPerform($userId, $projectId, 'project.read', $tenantId, false)) {
    fail('project_owner should have project.read');
}
ok('project_owner has project.read');

// Add a collaborator and verify role
$collabId = 'edge:' . bin2hex(random_bytes(8));
if (!AccessGraph::addEdge(['id' => $collabId, 'type' => 'COLLABORATOR', 'from' => $projectNodeId, 'to' => $userNodeId])) {
    fail('add COLLABORATOR edge');
}
$roles = AccessGraph::resolveProjectRoles($userId, $projectId, $tenantId, false);
if (!in_array('collaborator', $roles, true)) {
    fail('expected collaborator role after COLLABORATOR edge');
}
ok('collaborator inferred');

// Test ORDER_PROJECT + ORDER_CUSTOMER path
if (!AccessGraph::addNode(['id' => $orderNodeId, 'type' => 'order', 'data' => ['status' => 'completed']])) {
    fail('add order node');
}
if (!AccessGraph::addEdge(['id' => 'edge:' . bin2hex(random_bytes(8)), 'type' => 'ORDER_PROJECT', 'from' => $projectNodeId, 'to' => $orderNodeId])) {
    fail('add ORDER_PROJECT');
}
if (!AccessGraph::addEdge(['id' => 'edge:' . bin2hex(random_bytes(8)), 'type' => 'ORDER_CUSTOMER', 'from' => $orderNodeId, 'to' => $userNodeId])) {
    fail('add ORDER_CUSTOMER');
}

$roles = AccessGraph::resolveProjectRoles($userId, $projectId, $tenantId, false);
if (!in_array('order_customer', $roles, true)) {
    fail('expected order_customer via order relation');
}
ok('order_customer inferred');

// Test tenant membership
if (!AccessGraph::addNode(['id' => $tenantNodeId, 'type' => 'tenant', 'data' => ['name' => 'Acme']])) {
    fail('add tenant node');
}
if (!AccessGraph::addEdge(['id' => 'edge:' . bin2hex(random_bytes(8)), 'type' => 'BELONGS_TO', 'from' => $projectNodeId, 'to' => $tenantNodeId])) {
    fail('add BELONGS_TO');
}
if (!AccessGraph::addEdge(['id' => 'edge:' . bin2hex(random_bytes(8)), 'type' => 'MEMBER_OF', 'from' => $userNodeId, 'to' => $tenantNodeId])) {
    fail('add MEMBER_OF');
}

$roles = AccessGraph::resolveProjectRoles($userId, $projectId, $tenantId, false);
if (!in_array('tenant_member', $roles, true)) {
    fail('expected tenant_member via tenant membership');
}
ok('tenant_member inferred');

// Cleanup graph
@unlink(ServiceHelpers::dataPath('cms', 'graph.json'));

echo "All access graph tests passed\n";
exit(0);
