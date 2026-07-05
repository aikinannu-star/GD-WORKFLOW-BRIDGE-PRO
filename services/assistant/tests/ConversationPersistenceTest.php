<?php
require_once __DIR__ . '/../ConversationManager.php';
require_once __DIR__ . '/../repositories/ConversationRepositoryInterface.php';
require_once __DIR__ . '/../repositories/FileConversationRepository.php';

$storageDir = __DIR__ . '/../../data/assistant/tests/conversations';
if (is_dir($storageDir)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storageDir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($storageDir);
}

$repo = new FileConversationRepository($storageDir);
$manager = new ConversationManager($repo);

$created = $manager->createSession('sess-persist', ['assistantId' => 'support-assistant'], 'default', 'tester');
if (($created['metadata']['assistantId'] ?? null) !== 'support-assistant') {
    fwrite(STDERR, "Conversation metadata was not persisted on create\n");
    exit(1);
}

$manager->appendMessage('sess-persist', ['role' => 'user', 'text' => 'Hello there']);
$manager->appendMessage('sess-persist', ['role' => 'assistant', 'text' => 'Hello back']);

$reloadedManager = new ConversationManager($repo);
$session = $reloadedManager->getSession('sess-persist');
if ($session === null) {
    fwrite(STDERR, "Conversation was not reloaded from storage\n");
    exit(1);
}

if (count($session['history'] ?? []) !== 2) {
    fwrite(STDERR, "Conversation history was not appended and reloaded correctly\n");
    exit(1);
}

if (($session['history'][0]['text'] ?? null) !== 'Hello there' || ($session['history'][1]['role'] ?? null) !== 'assistant') {
    fwrite(STDERR, "Conversation history contents were not restored as expected\n");
    exit(1);
}

fwrite(STDOUT, "Conversation persistence test passed\n");
