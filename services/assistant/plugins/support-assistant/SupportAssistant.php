<?php

require_once __DIR__ . '/../../AssistantInterface.php';
require_once __DIR__ . '/../../AssistantService.php';
require_once __DIR__ . '/../../AssistantPipeline.php';
require_once __DIR__ . '/../../ToolRegistry.php';
require_once __DIR__ . '/../../AssistantContext.php';
require_once __DIR__ . '/../../ModelProviderInterface.php';
require_once __DIR__ . '/../../ConversationManager.php';
require_once __DIR__ . '/../../memory/MemoryStore.php';
require_once __DIR__ . '/../../memory/MemoryPolicy.php';
require_once __DIR__ . '/../../../dispatcher/events/RuntimeEventEmitter.php';

class SupportAssistant implements AssistantInterface
{
    private AssistantService $service;
    private ?ConversationManager $conversationManager;

    public function __construct(
        ToolRegistry $toolRegistry,
        ModelProviderInterface $provider,
        ?ConversationManager $conversationManager = null,
        ?RuntimeEventEmitter $eventEmitter = null,
        ?MemoryStore $memoryStore = null,
        ?MemoryPolicy $memoryPolicy = null
    ) {
        $pipeline = new AssistantPipeline($toolRegistry, $provider, $eventEmitter ?? new RuntimeEventEmitter(), $memoryStore);
        $this->service = new AssistantService($pipeline, $eventEmitter);
        $this->conversationManager = $conversationManager;
    }

    public function id(): string
    {
        return 'support-assistant';
    }

    public function name(): string
    {
        return 'Support Assistant';
    }

    public function description(): string
    {
        return 'Handles support conversations and executes dispatcher tools as needed.';
    }

    public function capabilities(): array
    {
        return ['conversation', 'tool_execution'];
    }

    public function tools(): array
    {
        return ['dispatcher_action', 'workflow_execute'];
    }

    public function handleConversation(array $context): array
    {
        $assistantContext = new AssistantContext(
            $this->id(),
            $context['conversationId'] ?? uniqid('conv_', true),
            $context['sessionId'] ?? uniqid('sess_', true),
            $context['tenantId'] ?? 'default',
            $context['userId'] ?? null
        );

        $message = $context['message'] ?? '';
        if ($this->conversationManager && $assistantContext->sessionId) {
            $this->conversationManager->appendMessage($assistantContext->sessionId, [
                'role' => 'user',
                'text' => $message,
                'conversationId' => $assistantContext->conversationId,
            ]);
        }

        $result = $this->service->handleMessage($assistantContext, $message);
        if ($this->conversationManager && $assistantContext->sessionId) {
            $this->conversationManager->appendMessage($assistantContext->sessionId, [
                'role' => 'assistant',
                'text' => $result['assistantText'] ?? '',
                'conversationId' => $assistantContext->conversationId,
            ]);
        }

        return $result;
    }
}
