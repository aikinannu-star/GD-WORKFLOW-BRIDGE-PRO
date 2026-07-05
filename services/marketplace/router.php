<?php
// Simple router to direct all requests to server.php
$requested_file = $_SERVER['REQUEST_URI'];
$requested_file = parse_url($requested_file, PHP_URL_PATH);

// If the file doesn't exist as a real file, route to server.php
if (!is_file(__DIR__ . $requested_file) && !is_dir(__DIR__ . $requested_file)) {
    include __DIR__ . '/server.php';
} else if (is_file(__DIR__ . $requested_file)) {
    return false; // Serve the static file
} else if (is_dir(__DIR__ . $requested_file)) {
    // Try to serve index.php or index.html from the directory
    if (is_file(__DIR__ . $requested_file . '/index.php')) {
        include __DIR__ . $requested_file . '/index.php';
    } else if (is_file(__DIR__ . $requested_file . '/index.html')) {
        return false;
    } else {
        include __DIR__ . '/server.php';
    }
}
