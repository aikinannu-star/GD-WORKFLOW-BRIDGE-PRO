<?php
chdir(__DIR__ . '/..');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/operations-center';
ob_start();
include 'services/marketplace/server.php';
$html = ob_get_clean();
if (strpos($html, 'Intelligence Health') !== false) {
    echo "OK: Intelligence Health banner present\n";
    exit(0);
}
echo "FAIL: Intelligence Health banner missing\n";
exit(2);
