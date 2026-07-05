<?php
chdir(__DIR__ . '/..');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/v1/intelligence-health';
ob_start();
include 'services/marketplace/server.php';
$output = ob_get_clean();

// Parse as JSON
$data = json_decode($output, true);
if (!$data) {
    echo "FAIL: Not valid JSON\n";
    exit(2);
}

if (empty($data['status'])) {
    echo "FAIL: Missing status field\n";
    exit(2);
}

if (!isset($data['findings'])) {
    echo "FAIL: Missing findings field\n";
    exit(2);
}

if (!isset($data['recommendations'])) {
    echo "FAIL: Missing recommendations field\n";
    exit(2);
}

echo "OK: Intelligence-health API has status, findings, and recommendations\n";
echo "Status: " . $data['status'] . "\n";
echo "Findings count: " . count($data['findings']) . "\n";
echo "Recommendations count: " . count($data['recommendations']) . "\n";
exit(0);
