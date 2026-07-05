<?php

$root = dirname(__DIR__);
$tests = [
    [
        'name' => 'Assistant runtime regression suite',
        'command' => [PHP_BINARY, $root . '/assistant/tests/run_assistant_tests.php'],
    ],
    [
        'name' => 'Gateway shutdown regression',
        'command' => [PHP_BINARY, $root . '/gateway/tests/GatewayGracefulShutdownTest.php'],
    ],
    [
        'name' => 'Composite shutdown regression',
        'command' => [PHP_BINARY, $root . '/tests/CompositeShutdownScenarioTest.php'],
    ],
    [
        'name' => 'Composite repeat-cycle validation',
        'command' => [PHP_BINARY, $root . '/tests/CompositeShutdownRepeatCycleValidationTest.php'],
    ],
];

$failures = [];
foreach ($tests as $test) {
    $name = $test['name'];
    $command = $test['command'];
    $commandLine = implode(' ', array_map('escapeshellarg', $command));
    echo "[RUN] {$name}\n";
    $exitCode = 0;
    passthru($commandLine, $exitCode);
    if ($exitCode !== 0) {
        $failures[] = $name;
        echo "[FAIL] {$name} exited with code {$exitCode}\n";
    } else {
        echo "[PASS] {$name}\n";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Production readiness validation failed: " . implode(', ', $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Production readiness validation suite passed.\n");
