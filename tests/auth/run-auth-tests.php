<?php
$tests = [
    __DIR__ . '/test-access-graph.php',
    __DIR__ . '/test-access-middleware.php',
    __DIR__ . '/test-controlplaneauth.php',
];

foreach ($tests as $t) {
    echo "Running: $t\n";
    $cmd = 'php ' . escapeshellarg($t);
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);
    echo implode("\n", $output) . "\n";
    if ($ret !== 0) {
        echo "Test failed: $t (exit=$ret)\n";
        exit($ret);
    }
}

echo "All auth tests passed.\n";
exit(0);
