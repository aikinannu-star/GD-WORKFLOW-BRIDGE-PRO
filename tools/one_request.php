<?php
// Simple CLI helper to simulate a single HTTP request to the marketplace server.php
$method = $argv[1] ?? 'GET';
$uri = $argv[2] ?? '/marketplace-ui';
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI'] = $uri;
// Include the server script (it will echo output and exit)
require __DIR__ . '/../services/marketplace/server.php';
