<?php

require_once __DIR__ . '/../context/TokenEstimator.php';
require_once __DIR__ . '/../context/ContextPolicy.php';
require_once __DIR__ . '/../context/ConversationSummaryRepository.php';
require_once __DIR__ . '/../context/ConversationSummarizer.php';
require_once __DIR__ . '/../context/ContextWindowManager.php';
require_once __DIR__ . '/../ModelProviderInterface.php';
require_once __DIR__ . '/../LocalModelProvider.php';

// Test: Token estimation
$estimator = new TokenEstimator();

$shortText = "Hello world";
$longText = "This is a longer message with more words to count for token estimation";

$shortTokens = $estimator->estimateTokens($shortText);
$longTokens = $estimator->estimateTokens($longText);

if ($shortTokens <= 0 || $longTokens <= $shortTokens) {
    echo "Token estimation failed: short=$shortTokens, long=$longTokens" . PHP_EOL;
    exit(1);
}

// Test message-level token estimation
$message = [
    'role' => 'user',
    'content' => 'This is a test message',
];
$messageTokens = $estimator->estimateMessageTokens($message);

if ($messageTokens <= 0) {
    echo "Message token estimation failed" . PHP_EOL;
    exit(1);
}

// Test: Context policies
$policies = [
    ContextPolicy::compact(),
    ContextPolicy::balanced(),
    ContextPolicy::generous(),
    ContextPolicy::unlimited(),
];

foreach ($policies as $policy) {
    if ($policy->maxContextTokens <= 0 && $policy->name !== 'unlimited') {
        echo "Policy {$policy->name} has invalid maxContextTokens" . PHP_EOL;
        exit(1);
    }
}

// Test: Policy deserialization
$customPolicy = [
    'name' => 'custom',
    'maxHistoryMessages' => 25,
    'maxContextTokens' => 3000,
    'summarizeAfterMessages' => 15,
    'summarizeAfterTokens' => 2500,
];
$restored = ContextPolicy::fromArray($customPolicy);

if ($restored->name !== 'custom' || $restored->maxHistoryMessages !== 25) {
    echo "Policy deserialization failed" . PHP_EOL;
    exit(1);
}

// Test: Summary repository
$summaryRepo = new FileConversationSummaryRepository();
$conversationId = 'test-context-' . uniqid();

$summary1 = [
    'fromMessageIndex' => 0,
    'toMessageIndex' => 9,
    'messageCount' => 10,
    'originalTokens' => 500,
    'summaryTokens' => 50,
    'summary' => 'First 10 messages discussed user authentication setup',
];

$saved = $summaryRepo->save($conversationId, $summary1);

if (empty($saved['savedAt'])) {
    echo "Summary repository save failed" . PHP_EOL;
    exit(1);
}

// Test: Retrieve latest summary
$latest = $summaryRepo->getLatest($conversationId);

if ($latest === null || $latest['summary'] !== $summary1['summary']) {
    echo "Summary repository retrieval failed" . PHP_EOL;
    exit(1);
}

// Save another summary
$summary2 = [
    'fromMessageIndex' => 10,
    'toMessageIndex' => 19,
    'messageCount' => 10,
    'originalTokens' => 450,
    'summaryTokens' => 45,
    'summary' => 'Next 10 messages discussed API integration',
];

$summaryRepo->save($conversationId, $summary2);

// Test: Retrieve all summaries
$allSummaries = $summaryRepo->getAll($conversationId);

if (count($allSummaries) !== 2) {
    echo "Summary repository getAll failed: expected 2, got " . count($allSummaries) . PHP_EOL;
    exit(1);
}

// Test: Conversation summarizer
$modelProvider = new LocalModelProvider();
$summarizer = new ConversationSummarizer($modelProvider, $summaryRepo, $estimator);

$history = [
    ['role' => 'user', 'content' => 'I need help setting up authentication'],
    ['role' => 'assistant', 'content' => 'I can help with that. What authentication method do you prefer?'],
    ['role' => 'user', 'content' => 'OAuth2 would be ideal'],
    ['role' => 'assistant', 'content' => 'OAuth2 is a good choice. Here are the steps...'],
];

// Find summarization points
$points = $summarizer->findSummarizationPoints($history, 200);

if (empty($points)) {
    echo "Summarization points finding failed" . PHP_EOL;
    exit(1);
}

// Test: Context window manager
$policy = ContextPolicy::balanced();
$manager = new ContextWindowManager($estimator, $summarizer, $policy);

// Test context stats
$stats = $manager->getContextStats($history);

if (empty($stats) || $stats['messageCount'] !== 4) {
    echo "Context stats failed: " . json_encode($stats) . PHP_EOL;
    exit(1);
}

// Test: Check if context management needed
$longHistory = [];
for ($i = 0; $i < 60; $i++) {
    $longHistory[] = [
        'role' => $i % 2 === 0 ? 'user' : 'assistant',
        'content' => 'This is message ' . $i . ' with significant additional text to ensure we exceed token limits during context management testing. The content deliberately contains extra words to inflate token count.',
    ];
}

$needsManagement = $manager->needsContextManagement($longHistory, 1000);

// Debug: check stats
$longStats = $manager->getContextStats($longHistory);

if (!$needsManagement) {
    echo "Context management detection failed. Stats: " . json_encode($longStats) . PHP_EOL;
    exit(1);
}

// Test: Get recommended action
$action = $manager->getRecommendedAction($longHistory, 1000);

if (empty($action)) {
    echo "Recommended action detection failed" . PHP_EOL;
    exit(1);
}

// Test: Apply context management
$managedHistory = $manager->applyContextManagement($conversationId, $longHistory, 1000);

if (count($managedHistory) >= count($longHistory)) {
    echo "Context management pruning failed" . PHP_EOL;
    exit(1);
}

// Verify important messages preserved
$hasUserMessage = false;
foreach ($managedHistory as $msg) {
    if ($msg['role'] === 'user') {
        $hasUserMessage = true;
        break;
    }
}

if (!$hasUserMessage) {
    echo "Important messages not preserved during pruning" . PHP_EOL;
    exit(1);
}

// Test: Policy switching
$compactPolicy = ContextPolicy::compact();
$manager->setPolicy($compactPolicy);

$currentPolicy = $manager->getPolicy();

if ($currentPolicy->name !== 'compact') {
    echo "Policy switching failed" . PHP_EOL;
    exit(1);
}

echo "Context window management test passed" . PHP_EOL;
