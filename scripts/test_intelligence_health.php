<?php
chdir(__DIR__ . '/..');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/v1/intelligence-health';
include 'services/marketplace/server.php';
