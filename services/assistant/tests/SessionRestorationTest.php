<?php

require_once __DIR__ . '/../RuntimeBootstrap.php';
require_once __DIR__ . '/../SessionRestorer.php';
require_once __DIR__ . '/../ConversationMetadata.php';

// Test: Session restoration and recovery
$bootstrap1 = RuntimeBootstrap::bootstrap();
$runtime1 = $bootstrap1['runtime'];
$sessionRestorer1 = $bootstrap1['sessionRestorer'];

// Create a new conversation
$conversationId = 'test-conversation-' . uniqid();
$session = $runtime1->conversationManager->createSession(
    $conversationId,
    [
        'assistantId' => 'support-assistant',
        'userId' => 'user-123',
        'modelProvider' => 'ollama',
    ]
);

// Add messages to the conversation
$runtime1->conversationManager->appendMessage($conversationId, [
    'role' => 'user',
    'content' => 'Hello, I need help with my account',
    'timestamp' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
]);

$runtime1->conversationManager->appendMessage($conversationId, [
    'role' => 'assistant',
    'content' => 'I can help you with that. What seems to be the issue?',
    'timestamp' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
]);

$firstHistory = $runtime1->conversationManager->getHistory($conversationId);
$expectedCount = 2;

if (count($firstHistory) !== $expectedCount) {
    echo "Initial message count incorrect: " . count($firstHistory) . " vs " . $expectedCount . PHP_EOL;
    exit(1);
}

// Simulate runtime restart by creating a new bootstrap
$bootstrap2 = RuntimeBootstrap::bootstrap();
$runtime2 = $bootstrap2['runtime'];
$sessionRestorer2 = $bootstrap2['sessionRestorer'];

// Restore the conversation
$restored = $sessionRestorer2->restoreConversation($conversationId);

if (!$restored) {
    echo "Failed to restore conversation" . PHP_EOL;
    exit(1);
}

$restoredSession = $restored['session'];
$restoredMetadata = $restored['metadata'];
$restoredHistory = $restored['session']['history'] ?? [];

// Verify metadata was restored
if ($restoredMetadata->conversationId !== $conversationId) {
    echo "Conversation ID mismatch after restore" . PHP_EOL;
    exit(1);
}

if ($restoredMetadata->assistantId !== 'support-assistant') {
    echo "Assistant ID mismatch after restore" . PHP_EOL;
    exit(1);
}

// Verify history was restored
if (count($restoredHistory) !== $expectedCount) {
    echo "History count mismatch after restore: " . count($restoredHistory) . " vs " . $expectedCount . PHP_EOL;
    exit(1);
}

// Verify first message content
if ($restoredHistory[0]['content'] !== 'Hello, I need help with my account') {
    echo "First message content mismatch after restore" . PHP_EOL;
    exit(1);
}

// Continue the conversation
$continuation = $sessionRestorer2->continueConversation(
    $conversationId,
    [
        'role' => 'user',
        'content' => 'I forgot my password',
        'timestamp' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
    ],
    'support-assistant'
);

$continuedHistory = $runtime2->conversationManager->getHistory($conversationId);

if (count($continuedHistory) !== $expectedCount + 1) {
    echo "Message count after continuation incorrect: " . count($continuedHistory) . " vs " . ($expectedCount + 1) . PHP_EOL;
    exit(1);
}

// Verify the new message was appended
if ($continuedHistory[$expectedCount]['content'] !== 'I forgot my password') {
    echo "New message not appended correctly after continuation" . PHP_EOL;
    exit(1);
}

// Test archiving
$sessionRestorer2->archiveConversation($conversationId);
$archivedSession = $runtime2->conversationManager->getSession($conversationId);
$archivedMetadata = new ConversationMetadata($archivedSession['metadata'] ?? []);

if ($archivedMetadata->status !== 'archived') {
    echo "Conversation not marked as archived" . PHP_EOL;
    exit(1);
}

// Test that archived conversations cannot be restored
try {
    $sessionRestorer2->restoreConversation($conversationId);
    // Try to continue should fail
    echo "Archived conversation should not be restorable" . PHP_EOL;
    exit(1);
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'closed') === false) {
        echo "Unexpected error when trying to restore archived conversation: " . $e->getMessage() . PHP_EOL;
        exit(1);
    }
}

echo "Session restoration and recovery test passed" . PHP_EOL;
