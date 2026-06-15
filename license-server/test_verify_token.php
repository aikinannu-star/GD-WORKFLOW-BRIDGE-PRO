<?php
// Quick script to verify a JWT using the plugin's License_Client class (CLI only)
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/../');
if (!defined('GDWB_PATH')) define('GDWB_PATH', realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR);
require_once GDWB_PATH . 'includes/Admin/License_Client.php';

use GDWB\Admin\License_Client;

// Request token from the local license server for a test key
$post = http_build_query(['license_key' => 'TEST-LICENSE-KEY-12345-ABCDE-FGHIJ-KLMNO', 'site' => 'http://localhost']);
$opts = ['http' => ['method' => 'POST', 'header' => "Content-type: application/x-www-form-urlencoded\r\n", 'content' => $post, 'timeout' => 5]];
$context = stream_context_create($opts);
$resp = @file_get_contents('http://127.0.0.1:8001/api/v1/validate', false, $context);
if ($resp === false) {
    echo "Failed to fetch token from local server.\n";
    exit(1);
}
$data = json_decode($resp, true);
if (empty($data['token'])) {
    echo "No token returned: " . $resp . "\n";
    exit(1);
}
$token = $data['token'];

$client = new License_Client();
$valid = $client->isJwtValid($token);
echo "isJwtValid: " . ($valid ? 'true' : 'false') . "\n";
$payload = $client->getPayloadFromJwt($token);
echo "payload:\n";
print_r($payload);
