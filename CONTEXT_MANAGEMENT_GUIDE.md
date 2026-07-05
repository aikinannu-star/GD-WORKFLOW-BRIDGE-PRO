# Conversation Context Management Guide

## Overview

Context window management is essential for production AI assistants. As conversations grow, they consume increasing tokens, eventually exceeding model limits. This system provides intelligent history pruning, automatic summarization, and token budgeting to keep conversations within bounds while preserving important context.

## Architecture

### Core Components

#### 1. **TokenEstimator**
Estimates token counts for text using word-based approximation (configurable ratio).

```php
$estimator = new TokenEstimator(1.3); // 1.3 tokens per word

$tokens = $estimator->estimateTokens("Hello world");
$messageTokens = $estimator->estimateMessageTokens(['role' => 'user', 'content' => '...']);
$historyTokens = $estimator->estimateHistoryTokens($history);
$cost = $estimator->getConversationCost($history, 0.000015); // Cost per token
```

**Note:** For production, integrate with actual tokenizers:
- GPT-3: `gpt2-php` library
- Claude: Anthropic's tokenizer
- Custom: Your model's specific tokenization

#### 2. **ContextPolicy**
Defines retention and pruning strategies with predefined profiles.

```php
// Predefined policies
$compact = ContextPolicy::compact();        // 20 messages, 2000 tokens
$balanced = ContextPolicy::balanced();      // 50 messages, 4000 tokens
$generous = ContextPolicy::generous();      // 200 messages, 8000 tokens
$unlimited = ContextPolicy::unlimited();    // No limits

// Custom policy
$custom = new ContextPolicy(
    name: 'custom',
    maxHistoryMessages: 30,
    maxContextTokens: 3000,
    summarizeAfterMessages: 20,
    summarizeAfterTokens: 2500,
    enableAutoSummarization: true,
    pruneStrategy: 'keep-recent',
    keepSystemMessages: true,
    keepUserFirstMessage: true
);
```

**Policy Attributes:**

| Attribute | Purpose |
|-----------|---------|
| `maxHistoryMessages` | Hard limit on message count |
| `maxContextTokens` | Hard limit on token count |
| `summarizeAfterMessages` | Trigger summarization at message count |
| `summarizeAfterTokens` | Trigger summarization at token count |
| `enableAutoSummarization` | Automatic vs. manual pruning |
| `pruneStrategy` | How to select messages to remove |
| `keepSystemMessages` | Always preserve system instructions |
| `keepUserFirstMessage` | Always preserve first user message |

#### 3. **ConversationSummaryRepository**
Persists conversation summaries for long-term context preservation.

```php
$repo = new FileConversationSummaryRepository();

// Save a summary
$summary = [
    'fromMessageIndex' => 0,
    'toMessageIndex' => 49,
    'messageCount' => 50,
    'originalTokens' => 2000,
    'summaryTokens' => 200,
    'summary' => 'User discussed authentication setup and API integration'
];
$repo->save($conversationId, $summary);

// Retrieve summaries
$latest = $repo->getLatest($conversationId);
$all = $repo->getAll($conversationId);
$after = $repo->getSummariesAfter($conversationId, 100);
```

**Storage Location:**
```
services/assistant/data/assistant/summaries/{conversationId}/summary_*.json
```

#### 4. **ConversationSummarizer**
Automatically summarizes conversation segments using the model provider.

```php
$summarizer = new ConversationSummarizer($modelProvider, $summaryRepo, $tokenEstimator);

// Summarize specific messages
$summary = $summarizer->summarizeMessages(
    $conversationId,
    array_slice($history, 0, 50),
    fromIndex: 0,
    toIndex: 49
);

// Find optimal summarization points
$points = $summarizer->findSummarizationPoints($history, tokenBudget: 500);
// Returns: [
//   ['fromIndex' => 0, 'toIndex' => 15, 'messageCount' => 16, 'tokens' => 480],
//   ['fromIndex' => 16, 'toIndex' => 35, 'messageCount' => 20, 'tokens' => 520],
//   ...
// ]

// Rebuild history with summaries injected
$withSummaries = $summarizer->rebuildWithSummaries($conversationId, $history);
```

#### 5. **ContextWindowManager**
Orchestrates all components to intelligently manage context windows.

```php
$manager = new ContextWindowManager($tokenEstimator, $summarizer, $policy);

// Apply full context management
$managed = $manager->applyContextManagement($conversationId, $history, $tokenBudget);

// Check if management needed
$needed = $manager->needsContextManagement($history, 4000);

// Get recommended action
$action = $manager->getRecommendedAction($history, 4000);
// Returns: 'trim_messages', 'summarize_and_trim', 'trim_only', 'consider_summarization', or null

// Get context statistics
$stats = $manager->getContextStats($history);
// {
//   "messageCount": 50,
//   "totalTokens": 3500,
//   "estimatedCost": 0.0525,
//   "avgTokensPerMessage": 70,
//   "oldestMessage": "2024-01-15T10:00:00+00:00",
//   "newestMessage": "2024-01-15T11:30:00+00:00"
// }

// Switch policies dynamically
$manager->setPolicy(ContextPolicy::compact());
```

## Usage Patterns

### Pattern 1: Automatic Context Management on Every Message

```php
$bootstrap = RuntimeBootstrap::bootstrap();
$manager = $bootstrap['contextWindowManager'];
$conversationManager = $bootstrap['runtime']->conversationManager;

// Add new message
$conversationManager->appendMessage($conversationId, $newMessage);

// Retrieve full history
$history = $conversationManager->getHistory($conversationId);

// Apply automatic management
$managedHistory = $manager->applyContextManagement($conversationId, $history, 4000);

// Use managed history for model context
$modelInput = [
    'system' => 'You are a helpful assistant',
    'history' => $managedHistory,
    'userMessage' => $newMessage['content']
];
```

### Pattern 2: Proactive Monitoring and Alerts

```php
$stats = $manager->getContextStats($history);

if ($stats['totalTokens'] > 3500) {
    $action = $manager->getRecommendedAction($history, 4000);
    
    if ($action === 'summarize_and_trim') {
        $logger->notice('Summarizing conversation', [
            'conversationId' => $conversationId,
            'currentTokens' => $stats['totalTokens'],
            'estimatedCost' => $stats['estimatedCost']
        ]);
    }
}
```

### Pattern 3: Session Restoration with Context Management

```php
$restorer = $bootstrap['sessionRestorer'];
$manager = $bootstrap['contextWindowManager'];

// Restore conversation
$restored = $restorer->restoreConversation($conversationId);
$history = $restored['history'];

// Apply context management to restored history
$managedHistory = $manager->applyContextManagement($conversationId, $history, 4000);

// Continue conversation
$restorer->continueConversation($conversationId, $userMessage);
```

### Pattern 4: Cost Tracking and Budgeting

```php
$estimator = $bootstrap['tokenEstimator'];

// Track costs per conversation
$conversationCost = $estimator->getConversationCost($history);
$monthlyBudget = 100.00; // $100/month
$percentUsed = ($conversationCost / $monthlyBudget) * 100;

// Alert if approaching budget
if ($percentUsed > 80) {
    $logger->warning('Conversation approaching budget limit', [
        'used' => $conversationCost,
        'budget' => $monthlyBudget,
        'percentUsed' => $percentUsed
    ]);
}
```

### Pattern 5: Dynamic Policy Selection

```php
// Choose policy based on conversation characteristics
if ($messageCount > 100 || $estimatedCost > 50) {
    $policy = ContextPolicy::compact();
} elseif ($messageCount > 50) {
    $policy = ContextPolicy::balanced();
} else {
    $policy = ContextPolicy::generous();
}

$manager->setPolicy($policy);
```

## Pruning Strategy

The system uses a multi-stage approach to reduce history while preserving important context:

### Stage 1: Identify Important Messages
Always preserved:
- System messages (instructions)
- First user message
- Messages with tool calls
- Most recent user message
- Messages marked with `metadata.important`

### Stage 2: Automatic Summarization (if enabled)
- Identifies summarization points based on token budget
- Calls model to generate summaries of older segments
- Persists summaries to repository
- Replaces summarized messages with summary injection

### Stage 3: Aggressive Pruning
- Removes old messages starting from oldest
- Works backwards to preserve recent context
- Maintains important messages even if over budget
- Stops when within token budget

## Storage Formats

### Conversation Records
```json
{
    "sessionId": "conv-abc123",
    "conversationId": "conv-abc123",
    "tenantId": "default",
    "userId": "user-123",
    "metadata": {
        "assistantId": "support-assistant",
        "status": "active",
        "messageCount": 50,
        "modelProvider": "ollama"
    },
    "history": [
        {
            "role": "user",
            "content": "...",
            "timestamp": "2024-01-15T10:00:00+00:00"
        },
        ...
    ],
    "createdAt": "2024-01-15T10:00:00+00:00",
    "updatedAt": "2024-01-15T11:30:00+00:00"
}
```

### Summary Records
```json
{
    "conversationId": "conv-abc123",
    "fromMessageIndex": 0,
    "toMessageIndex": 49,
    "messageCount": 50,
    "originalTokens": 2000,
    "summaryTokens": 200,
    "summary": "User discussed authentication setup...",
    "createdAt": "2024-01-15T10:30:00+00:00",
    "savedAt": "2024-01-15T10:30:00+00:00"
}
```

### Managed History with Summaries Injected
```php
[
    ['role' => 'user', 'content' => 'First question', ...],
    [
        'role' => 'system',
        'content' => 'Previous conversation summary:\nUser discussed authentication setup...',
        'metadata' => [
            'type' => 'summary',
            'fromMessageIndex' => 1,
            'toMessageIndex' => 49,
            'originalTokens' => 1900,
            'summaryTokens' => 150,
            'compressed' => '92.1%'
        ]
    ],
    ['role' => 'user', 'content' => 'Recent question', ...],
    ['role' => 'assistant', 'content' => '...', ...],
]
```

## Configuration

### Bootstrap Options

```php
$bootstrap = RuntimeBootstrap::bootstrap([
    'tokens_per_word' => 1.3,  // Adjust for your tokenizer
    'context_policy' => [
        'name' => 'custom',
        'maxHistoryMessages' => 50,
        'maxContextTokens' => 4000,
        'summarizeAfterMessages' => 30,
        'summarizeAfterTokens' => 3000,
        'enableAutoSummarization' => true,
    ],
    'summaries_path' => '/custom/summaries/path',
]);

$manager = $bootstrap['contextWindowManager'];
$estimator = $bootstrap['tokenEstimator'];
```

## Performance Considerations

### Token Estimation
- Word-based approximation: O(n) per message
- No external API calls required
- Highly accurate for typical conversations (±5%)

### Summarization
- Model calls: Only when needed (configurable thresholds)
- Async-friendly: Can offload to background tasks
- Compressed storage: Summaries typically 10-20% of original size

### History Retrieval
- File-based storage: Lazy loaded from disk
- Database backend: Index-friendly with prepared statements
- Memory usage: Only active conversations in memory

### Recommended Limits

| Scenario | maxHistoryMessages | maxContextTokens |
|----------|-------------------|-----------------|
| Mobile app | 20 | 2,000 |
| Web chat | 50 | 4,000 |
| Long research | 200 | 8,000 |
| Analysis tools | Unlimited | 12,000+ |

## Migration Paths

### From File to Database

Implement `ConversationSummaryRepositoryInterface`:

```php
class DatabaseSummaryRepository implements ConversationSummaryRepositoryInterface {
    private PDO $db;
    
    public function save(string $conversationId, array $summary): array {
        $stmt = $this->db->prepare(
            'INSERT INTO summaries (conversation_id, from_index, to_index, summary) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $conversationId,
            $summary['fromMessageIndex'],
            $summary['toMessageIndex'],
            $summary['summary']
        ]);
        return $summary;
    }
    
    // ... implement other interface methods
}
```

### Custom Tokenizers

Replace `TokenEstimator` initialization:

```php
class GPT3Tokenizer {
    private $encoder;
    
    public function estimateTokens(string $text): int {
        return count($this->encoder->encode($text));
    }
}

$estimator = new TokenEstimator(1.0);
$estimator->setTokenizer(new GPT3Tokenizer());
```

## Testing

Full test coverage in:
- `ContextWindowManagementTest.php` - Unit tests
- `ContextManagementIntegrationTest.php` - End-to-end workflow

Run tests:
```bash
php services/assistant/tests/ContextWindowManagementTest.php
php services/assistant/tests/ContextManagementIntegrationTest.php
```

## Next Steps

1. **Long-term Memory** - Retrieve relevant past conversations
2. **RAG Integration** - Augment context with knowledge base
3. **Adaptive Policies** - Learn optimal settings per user/domain
4. **Cost Optimization** - Multi-provider fallback based on cost
5. **Streaming** - Incremental token counting for streaming responses
