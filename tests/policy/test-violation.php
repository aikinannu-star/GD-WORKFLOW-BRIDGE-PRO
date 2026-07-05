<?php
require __DIR__ . '/../../services/lib/PolicyCompiler/Violation.php';

$v = new Violation('id1','rule_test','warning','test message','hint',['file'=>'x.php']);
if ($v->getId() !== 'id1') { echo "FAIL id\n"; exit(1); }
if ($v->getRule() !== 'rule_test') { echo "FAIL rule\n"; exit(1); }
if ($v->getSeverity() !== 'warning') { echo "FAIL severity\n"; exit(1); }
if ($v->getMessage() !== 'test message') { echo "FAIL message\n"; exit(1); }
if ($v->getRemediation() !== 'hint') { echo "FAIL remediation\n"; exit(1); }
if ($v->getLocation()['file'] !== 'x.php') { echo "FAIL location\n"; exit(1); }
echo "PASS: Violation object\n";