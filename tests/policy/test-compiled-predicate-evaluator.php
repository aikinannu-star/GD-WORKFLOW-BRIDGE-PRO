<?php
require __DIR__ . '/../../services/lib/PolicyCompiler/RuleGraph.php';
require __DIR__ . '/../../services/lib/PolicyCompiler/Violation.php';
require __DIR__ . '/../../services/lib/PolicyCompiler/PredicateEvaluator.php';
require __DIR__ . '/../../services/lib/PolicyCompiler/PolicyEvaluatorV2.php';

try {
    $e = PolicyEvaluatorV2::fromCompiledFile(__DIR__ . '/../../build/compiled-policy.json');
} catch (Exception $e) {
    echo "FAIL: could not load compiled policy artifact: " . $e->getMessage() . "\n";
    exit(1);
}

$results = $e->evaluateFile('CONTROL_PLANE_POLICY.yml');
if (!is_array($results)) {
    echo "FAIL: evaluation did not return array\n";
    exit(1);
}

foreach ($results as $violation) {
    if (!$violation instanceof Violation) {
        echo "FAIL: evaluator returned non-Violation object\n";
        exit(1);
    }
}

echo "PASS: compiled policy artifact evaluation returned " . count($results) . " violations\n";
