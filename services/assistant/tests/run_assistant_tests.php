<?php

$tests = [
    'SupportAssistantTest.php',
    'WorkflowExecutionTest.php',
    'ConditionalBranchingTest.php',
    'FileMemoryRepositoryTest.php',
    'ProviderFailureHandlingTest.php',
    'GracefulShutdownTest.php',
    'ExecutionReportResilienceTest.php',
    'ResilienceValidationSuite.php',
    'OperationalLoadBenchmark.php',
];

$baseDir = __DIR__;

foreach ($tests as $test) {
    $path = $baseDir . DIRECTORY_SEPARATOR . $test;
    if (!file_exists($path)) {
        fwrite(STDERR, "Test file not found: {$path}\n");
        exit(1);
    }

    echo "Running {$test}...\n";
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path);
    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        fwrite(STDERR, "Test failed: {$test} (exit code {$exitCode})\n");
        exit($exitCode);
    }
}

echo "Assistant runtime regression tests passed.\n";
