# Session Restoration and Recovery Guide

## Overview

Session restoration enables conversations to persist and resume across runtime restarts. This is essential for building resilient conversational AI systems where users can pick up where they left off.

## Architecture

### Components

1. **ConversationMetadata** - Enriched metadata model storing conversation state
   - `conversationId` - Unique conversation identifier
   - `assistantId` - Which assistant is handling this conversation
   - `userId` - Who initiated the conversation
   - `tenantId` - Multi-tenant isolation
   - `status` - active, paused, archived, closed
   - `modelProvider` - Which LLM provider was used
   - `lastWorkflowId` - Last executed workflow
   - `messageCount` - Total messages
   - `toolInvocations` - Total tools called

2. **SessionRestorer** - Orchestrates session restoration
   - `restoreConversation(id)` - Load and validate conversation
   - `continueConversation(id, message)` - Resume and append message
   - `archiveConversation(id)` - Mark as archived
   - `getContextWindow(id, maxMessages)` - Retrieve recent history

3. **FileConversationRepository** - Persists conversations to disk
   - JSON format for simple inspection
   - Metadata merged with conversation record
   - Timestamps tracked (created, updated)

## Usage Patterns

### Create a New Conversation

```php
$bootstrap = RuntimeBootstrap::bootstrap();
$runtime = $bootstrap['runtime'];

$conversationId = uniqid('conv-');
$session = $runtime->conversationManager->createSession(
    $conversationId,
    [
        'assistantId' => 'support-assistant',
        'userId' => 'user-123',
        'modelProvider' => 'ollama',
    ]
);

$runtime->conversationManager->appendMessage($conversationId, [
    'role' => 'user',
    'content' => 'Hello, I need help',
    'timestamp' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
]);
```

### Restore and Continue Conversation

```php
// Later... possibly in a different process or after restart
$bootstrap = RuntimeBootstrap::bootstrap();
$runtime = $bootstrap['runtime'];
$restorer = $bootstrap['sessionRestorer'];

// Restore the conversation
$restored = $restorer->restoreConversation($conversationId);

if ($restored) {
    $context = $restored['context']; // AssistantContext ready for execution
    $history = $restored['history']; // Full message history
    $metadata = $restored['metadata']; // ConversationMetadata
    
    // Continue with new message
    $continuation = $restorer->continueConversation($conversationId, [
        'role' => 'user',
        'content' => 'Follow up question',
        'timestamp' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
    ]);
}
```

### Archive Old Conversations

```php
$restorer->archiveConversation($conversationId);

// Further restoration attempts will fail
try {
    $restorer->restoreConversation($conversationId);
    // throws exception: conversation_closed_cannot_restore
} catch (Exception $e) {
    // Handle appropriately
}
```

### Retrieve Recent Context

```php
// Get only last 50 messages (for token budgeting)
$window = $restorer->getContextWindow($conversationId, 50);

// Use for context injection into model
$prompt = "Recent conversation:\n";
foreach ($window as $message) {
    $prompt .= $message['role'] . ": " . $message['content'] . "\n";
}
```

## Storage Format

Conversations are stored as JSON files at:
```
services/assistant/data/assistant/conversations/{sanitizedId}.json
```

Example structure:
```json
{
    "sessionId": "test-conversation-abc123",
    "conversationId": "test-conversation-abc123",
    "tenantId": "default",
    "userId": "user-123",
    "metadata": {
        "assistantId": "support-assistant",
        "userId": "user-123",
        "tenantId": "default",
        "status": "active",
        "modelProvider": "ollama",
        "messageCount": 2,
        "toolInvocations": 0,
        "tags": [],
        "metadata": {}
    },
    "history": [
        {
            "role": "user",
            "content": "Hello, I need help with my account",
            "timestamp": "2024-01-15T10:30:00+00:00"
        },
        {
            "role": "assistant",
            "content": "I can help. What's the issue?",
            "timestamp": "2024-01-15T10:30:05+00:00"
        }
    ],
    "createdAt": "2024-01-15T10:30:00+00:00",
    "updatedAt": "2024-01-15T10:30:05+00:00"
}
```

## Error Handling

### conversation_not_found
Attempted to restore/continue a conversation that doesn't exist
```php
$restored = $restorer->restoreConversation('nonexistent-id');
// returns null
```

### conversation_closed_cannot_restore
Attempted to restore an archived/closed conversation
```php
$restorer->archiveConversation($conversationId);
$restorer->restoreConversation($conversationId); // throws exception
```

### assistant_mismatch
Attempted to continue with different assistant than original
```php
// Original conversation created with assistant A
$restorer->continueConversation($conversationId, $message, 'assistant-b');
// throws exception
```

## Events Emitted

- `conversation.restored` - When a conversation is loaded
- `conversation.continued` - When a message is appended
- `conversation.archived` - When a conversation is marked archived

These can be hooked for observability/analytics:
```php
$runtime->eventEmitter->on('conversation.restored', function ($data) {
    // Log restoration: $data['conversationId'], $data['assistantId']
});
```

## Context Window Management

When conversations grow large, you should prune to recent context:

```php
// Keep only last 50 messages
$window = $restorer->getContextWindow($conversationId, 50);

// Build context for LLM with size awareness
$tokenCount = 0;
$context = [];
foreach (array_reverse($window) as $message) {
    $tokens = estimateTokens($message['content']); // Your tokenizer
    if ($tokenCount + $tokens > 4000) {
        // Add summary/continuation indicator
        break;
    }
    array_unshift($context, $message);
    $tokenCount += $tokens;
}
```

## Multi-Process Safety

FileConversationRepository uses atomic file operations, but for production multi-process scenarios, consider:

1. **Database backend**: Implement `ConversationRepositoryInterface` with DB storage
2. **Distributed locks**: Add locking to prevent concurrent modifications
3. **Event sourcing**: Store immutable append-only logs instead of mutable state

Example implementation pattern:
```php
class DatabaseConversationRepository implements ConversationRepositoryInterface {
    private PDO $db;
    
    public function __construct(PDO $db) {
        $this->db = $db;
    }
    
    public function appendMessage(string $id, array $message): array {
        // Transaction with row-level locking
    }
}
```

## Testing

Full test coverage in [SessionRestorationTest.php](SessionRestorationTest.php):

- ✅ Create conversation
- ✅ Add messages
- ✅ Runtime restart (via new bootstrap)
- ✅ Restore conversation
- ✅ Verify metadata
- ✅ Verify history
- ✅ Continue conversation
- ✅ Archive conversation
- ✅ Prevent restore of archived conversations

## Next Steps

After session restoration, consider:

1. **Context Management** - Implement token budgeting and conversation pruning
2. **Memory Service** - Add short-term and long-term memory layers
3. **RAG Integration** - Retrieve relevant historical context when needed
4. **Database Migration** - Move from file storage to persistent database
