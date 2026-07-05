<?php

require_once __DIR__ . '/../memory/FileMemoryRepository.php';
require_once __DIR__ . '/../memory/MemoryRecord.php';

$basePath = __DIR__ . '/../data/test-memory';
if (is_dir($basePath)) {
    foreach (scandir($basePath) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        unlink($basePath . DIRECTORY_SEPARATOR . $entry);
    }
} else {
    mkdir($basePath, 0775, true);
}

$repository = new FileMemoryRepository($basePath, 3);
for ($i = 0; $i < 10; $i++) {
    $record = new MemoryRecord([
        'id' => 'memory_' . $i,
        'userId' => 'tester',
        'tenantId' => 'default',
        'type' => 'note',
        'content' => 'memory ' . $i,
        'tags' => ['alpha'],
        'confidence' => 1.0,
        'createdAt' => date(DATE_ATOM),
        'lastConfirmedAt' => date(DATE_ATOM),
    ]);
    $repository->save($record);
}

$records = $repository->listByUser('tester', 'default');
if (count($records) !== 3) {
    fwrite(STDERR, "Expected 3 records, got " . count($records) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "FileMemoryRepository regression test passed\n");
