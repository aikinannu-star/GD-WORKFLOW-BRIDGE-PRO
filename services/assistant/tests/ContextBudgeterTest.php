<?php

require_once __DIR__ . '/../context/ContextBudgeter.php';

$budgeter = new ContextBudgeter(72, 30);
$sections = [
    [
        'name' => 'conversation',
        'label' => 'Recent conversation',
        'priority' => 100,
        'content' => 'User asked for a detailed plan and the assistant responded with a long explanation about the launch schedule and milestones.',
        'metadata' => [],
    ],
    [
        'name' => 'documents',
        'label' => 'Documents',
        'priority' => 40,
        'content' => 'This document contains a very long checklist of items that should be discussed only if there is enough space in the prompt window.',
        'metadata' => [],
    ],
];

$result = $budgeter->budget($sections, 'Please summarize the current project status.', ['maxTokens' => 72, 'reserveTokens' => 30]);
$kept = $result['sections'];

if (count($kept) < 1) {
    echo 'Expected at least one section to survive budgeting' . PHP_EOL;
    exit(1);
}

if (($kept[0]['metadata']['budget']['kept'] ?? null) !== true) {
    echo 'Expected the highest-priority section to be kept' . PHP_EOL;
    exit(1);
}

if (($kept[1]['metadata']['budget']['kept'] ?? null) === true) {
    echo 'Expected the lower-priority section to be trimmed or skipped' . PHP_EOL;
    exit(1);
}

echo 'Context budgeter test passed' . PHP_EOL;
