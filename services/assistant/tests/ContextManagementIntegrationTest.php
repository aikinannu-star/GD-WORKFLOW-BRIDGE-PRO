<?php

require_once __DIR__ . '/../RuntimeBootstrap.php';

// Integration test: Full context window management workflow
$bootstrap = RuntimeBootstrap::bootstrap();

$runtime = $bootstrap['runtime'];
$contextManager = $bootstrap['contextWindowManager'];
$sessionRestorer = $bootstrap['sessionRestorer'];
$conversationManager = $runtime->conversationManager;

// Create a new conversation
$conversationId = 'test-context-integration-' . uniqid();
$session = $conversationManager->createSession(
    $conversationId,
    [
        'assistantId' => 'support-assistant',
        'userId' => 'user-123',
        'modelProvider' => 'ollama',
    ]
);

// Simulate a growing conversation
$history = [];
for ($i = 0; $i < 50; $i++) {
    $role = $i % 2 === 0 ? 'user' : 'assistant';
    $content = "Message $i: This is a conversational exchange with enough content to generate meaningful token counts for testing context management and pruning strategies.";

    $message = [
        'role' => $role,
        'content' => $content,
        'timestamp' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
    ];

    $history[] = $message;
    $conversationManager->appendMessage($conversationId, $message);
}

// Check context statistics before management
$statsBefore = $contextManager->getContextStats($history);

if ($statsBefore['messageCount'] !== 50) {
    echo "Initial message count incorrect" . PHP_EOL;
    exit(1);
}

// Check if context management is needed with a tight budget
$tightBudget = 1000;
$needsManagement = $contextManager->needsContextManagement($history, $tightBudget);

if (!$needsManagement) {
    echo "Context management should be needed with tight budget. Tokens: {$statsBefore['totalTokens']}" . PHP_EOL;
    exit(1);
}

// Get recommended action
$action = $contextManager->getRecommendedAction($history, $tightBudget);

if (empty($action)) {
    echo "Recommended action should not be empty" . PHP_EOL;
    exit(1);
}

// Apply context management
$managedHistory = $contextManager->applyContextManagement($conversationId, $history, $tightBudget);

// Verify management worked
$statsAfter = $contextManager->getContextStats($managedHistory);

if ($statsAfter['totalTokens'] > $tightBudget * 1.1) { // Allow 10% overage
    echo "Context management failed to stay within budget. After: {$statsAfter['totalTokens']} vs budget: {$tightBudget}" . PHP_EOL;
    exit(1);
}

if ($statsAfter['messageCount'] >= $statsBefore['messageCount']) {
    echo "Context management should have reduced message count" . PHP_EOL;
    exit(1);
}

// Verify important messages were preserved
$hasUserMessages = false;
foreach ($managedHistory as $msg) {
    if ($msg['role'] === 'user') {
        $hasUserMessages = true;
        break;
    }
}

if (!$hasUserMessages) {
    echo "Important messages not preserved during context management" . PHP_EOL;
    exit(1);
}

// Test policy switching
$compactPolicy = ContextPolicy::compact();
$contextManager->setPolicy($compactPolicy);

$veryTightBudget = 500;
$compactManaged = $contextManager->applyContextManagement($conversationId, $history, $veryTightBudget);

$compactStats = $contextManager->getContextStats($compactManaged);

if ($compactStats['messageCount'] >= $statsAfter['messageCount']) {
    echo "Compact policy should reduce more aggressively than balanced" . PHP_EOL;
    exit(1);
}

// Test restoration with managed context
$restored = $sessionRestorer->restoreConversation($conversationId);

if (!$restored) {
    echo "Failed to restore conversation after context management" . PHP_EOL;
    exit(1);
}

$restoredHistory = $restored['history'];

if (empty($restoredHistory)) {
    echo "Restored history is empty" . PHP_EOL;
    exit(1);
}

// Simulate applying context management to restored conversation
$restoredWithManagement = $contextManager->applyContextManagement($conversationId, $restoredHistory, $tightBudget);

if (empty($restoredWithManagement)) {
    echo "Failed to apply context management to restored history" . PHP_EOL;
    exit(1);
}

// Continue conversation with managed context
$newMessage = [
    'role' => 'user',
    'content' => 'New question after context management',
    'timestamp' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
];

$continuation = $sessionRestorer->continueConversation($conversationId, $newMessage, 'support-assistant');

if (!$continuation) {
    echo "Failed to continue conversation" . PHP_EOL;
    exit(1);
}

// Verify the new message was added
$finalHistory = $continuation['session']['history'];

if (empty($finalHistory) || $finalHistory[count($finalHistory) - 1]['content'] !== $newMessage['content']) {
    echo "New message not properly added to continued conversation" . PHP_EOL;
    exit(1);
}

// Verify cost estimation works
$estimator = $bootstrap['tokenEstimator'];

$testMessages = [
    ['role' => 'user', 'content' => 'What is the weather?'],
    ['role' => 'assistant', 'content' => 'The weather is sunny and warm today.'],
];

$totalTokens = $estimator->estimateHistoryTokens($testMessages);
$estimatedCost = $estimator->getConversationCost($testMessages, 0.000015);

if ($totalTokens <= 0 || $estimatedCost <= 0) {
    echo "Cost estimation failed" . PHP_EOL;
    exit(1);
}

echo "Full context window management integration test passed" . PHP_EOL;
