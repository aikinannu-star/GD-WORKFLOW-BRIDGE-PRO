<?php
require __DIR__ . '/../services/lib/PolicyCompiler/RuleGraph.php';
require __DIR__ . '/../services/lib/PolicyCompiler/PolicyEvaluator.php';

$g = new RuleGraph();
$g->addNode('rule:no_cms_imports','rule',['name'=>'no_cms_imports','enabled'=>true,'message'=>'msg','severity'=>'error']);
$e = new PolicyEvaluator($g);
$res = $e->evaluateFile('README.md');
echo 'VIOLATIONS: '.count($res).PHP_EOL;
foreach ($res as $v) {
    echo "- {$v['file']}: {$v['rule']} ({$v['severity']}) - {$v['message']}\n";
}
