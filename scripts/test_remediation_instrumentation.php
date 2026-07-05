<?php
chdir(__DIR__ . '/..');
require_once 'services/lib/ServiceHelpers.php';

// create two remediation events: one resolved successfully, one unresolved
$now = gmdate('c');
$events = [
    [
        'id' => ServiceHelpers::generateUuid(),
        'tenant_id' => 'tenant-A',
        'action' => 'auto_reconcile_missing_deps',
        'details' => ['success' => true, 'outcome' => 'success', 'resolved_at' => gmdate('c', strtotime('+2 hours'))],
        'created_at' => $now,
    ],
    [
        'id' => ServiceHelpers::generateUuid(),
        'tenant_id' => 'tenant-B',
        'action' => 'manual_review_keys',
        'details' => ['success' => false, 'outcome' => 'failed'],
        'created_at' => $now,
    ],
];

ServiceHelpers::saveJson('marketplace', 'remediation_events.json', $events);

// call intelligence-health route
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/v1/intelligence-health';
include 'services/marketplace/server.php';
