<?php
$tests = [
    'test-violation.php',
    'test-compiler-evaluator.php',
];
$root = __DIR__;
$fail = false;
foreach ($tests as $t) {
    echo "Running: $t\n";
    $out = null;
    $rc = null;
    $cmd = "php " . escapeshellarg($root . DIRECTORY_SEPARATOR . $t);
    exec($cmd, $out, $rc);
    foreach ($out as $line) echo $line . PHP_EOL;
    if ($rc !== 0) {
        echo "Test failed: $t (rc=$rc)\n";
        $fail = true;
    }
}
if ($fail) exit(1);
echo "All policy tests passed\n";
