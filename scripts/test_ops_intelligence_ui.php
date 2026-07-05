<?php
chdir(__DIR__ . '/..');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/operations-center';
ob_start();
include 'services/marketplace/server.php';
$html = ob_get_clean();
if (strpos($html, 'Platform Intelligence') !== false) {
    echo "OK: Platform Intelligence section present\n";
    exit(0);
}
echo "FAIL: Platform Intelligence section missing\n";
exit(2);
