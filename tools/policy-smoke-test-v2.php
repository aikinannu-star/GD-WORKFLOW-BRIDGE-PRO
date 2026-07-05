<?php
require __DIR__ . '/../services/lib/PolicyCompiler/RuleGraph.php';
require __DIR__ . '/../services/lib/PolicyCompiler/Violation.php';
require __DIR__ . '/../services/lib/PolicyCompiler/PolicyEvaluatorV2.php';

$g = new RuleGraph();
$g->addNode('rule:no_cms_imports','rule',['name'=>'no_cms_imports','enabled'=>true,'message'=>'msg','severity'=>'error']);
$g->addNode('rule:no_business_logic','rule',['name'=>'no_business_logic','enabled'=>true,'message'=>'biz','severity'=>'warning']);
$e = new PolicyEvaluatorV2($g);
$res = $e->evaluateFile('README.md');
echo 'VIOLATIONS: '.count($res).PHP_EOL;
foreach ($res as $v) {
    echo "- {$v->getLocation()['file']}: {$v->getRule()} ({$v->getSeverity()}) - {$v->getMessage()}\n";
}
