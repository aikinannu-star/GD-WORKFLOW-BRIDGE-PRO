<?php
require __DIR__ . '/../../services/lib/PolicyCompiler/RuleGraph.php';
require __DIR__ . '/../../services/lib/PolicyCompiler/PolicyCompiler.php';
require __DIR__ . '/../../services/lib/PolicyCompiler/PolicyEvaluatorV2.php';

// Compile policy
$compiler = new PolicyCompiler(__DIR__ . '/../../CONTROL_PLANE_POLICY.yml');
try {
    $compiler->loadPolicy();
    $graph = $compiler->compile();
} catch (Exception $e) {
    echo "FAIL: compile exception: " . $e->getMessage() . "\n";
    exit(1);
}

if (!($graph instanceof RuleGraph)) {
    echo "FAIL: compile did not return RuleGraph\n";
    exit(1);
}

echo "PASS: PolicyCompiler compile\n";

// Evaluator test (basic)
$e = new PolicyEvaluatorV2($graph);
$violations = $e->evaluateFile('README.md');
if (!is_array($violations)) { echo "FAIL: evaluator returned non-array\n"; exit(1); }

echo "PASS: PolicyEvaluatorV2 evaluateFile (returned " . count($violations) . " violations)\n";
