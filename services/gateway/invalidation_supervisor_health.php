<?php
require_once __DIR__ . '/../../lib/ServiceHelpers.php';

$path = ServiceHelpers::dataPath('gateway', 'invalidation_supervisor.json');
header('Content-Type: application/json');
if (!file_exists($path)) {
    echo json_encode(['status' => 'unknown', 'message' => 'supervisor not running']);
    exit;
}
$content = @file_get_contents($path);
if ($content === false) {
    echo json_encode(['status' => 'error', 'message' => 'unable to read health file']);
    exit;
}
echo $content;
