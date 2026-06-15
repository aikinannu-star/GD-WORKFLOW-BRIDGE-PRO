<?php
// Router for PHP built-in development server
// This allows the development server to route all requests to server.php
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static files directly if they exist
$filePath = __DIR__ . $requestUri;
if (is_file($filePath)) {
    return false; // Serve the actual file
}

// Route everything else to server.php
require __DIR__ . '/server.php';
