<?php
require __DIR__ . '/../services/lib/PolicyCompiler/PolicyCompiler.php';
require __DIR__ . '/../services/lib/PolicyCompiler/RuleGraph.php';

$outDir = __DIR__ . '/../build';
if (!is_dir($outDir)) mkdir($outDir, 0777, true);

$policyPath = __DIR__ . '/../CONTROL_PLANE_POLICY.yml';
$compiler = new PolicyCompiler($policyPath);
try {
    $compiler->loadPolicy();
    $graph = $compiler->compile();
    $compiler->saveCompiled($graph, $outDir . '/compiled-policy.json');
    echo "Compiled policy written to {$outDir}/compiled-policy.json\n";
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
