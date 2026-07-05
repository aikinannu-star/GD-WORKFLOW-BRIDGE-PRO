<?php

require_once __DIR__ . '/../AssistantContext.php';
require_once __DIR__ . '/../ModelProviderInterface.php';
require_once __DIR__ . '/ProviderInfo.php';
require_once __DIR__ . '/ModelProfile.php';
require_once __DIR__ . '/PipelineReport.php';
require_once __DIR__ . '/../../dispatcher/events/RuntimeEventEmitter.php';

class RuntimeExecutionContext
{
    private AssistantContext $assistantContext;
    private ?ProviderInfo $providerInfo;
    private ?ModelProfile $modelProfile;
    private array $memory;
    private array $workflow;
    private array $featureFlags;
    private array $executionOptions;
    private array $payload;
    private ?RuntimeEventEmitter $eventEmitter;
    private ?ModelProviderInterface $provider;
    private string $executionId;
    private string $conversationId;
    private string $assistantId;
    private ?string $sessionId;
    private ?string $tenantId;
    private ?string $userId;
    private array $conversation = [];
    private array $assembledContext = [];
    private string $prompt = '';
    private array $toolPlan = [];
    private array $toolResults = [];
    private array $providerResponse = [];
    private string $finalResponse = '';
    private ?PipelineReport $report = null;
    private array $metadata = [];

    public function __construct(AssistantContext $assistantContext, ?ProviderInfo $providerInfo = null, ?ModelProfile $modelProfile = null, array $payload = [], array $memory = [], array $workflow = [], array $featureFlags = [], array $executionOptions = [], ?RuntimeEventEmitter $eventEmitter = null, ?ModelProviderInterface $provider = null)
    {
        $this->assistantContext = $assistantContext;
        $this->providerInfo = $providerInfo;
        $this->modelProfile = $modelProfile;
        $this->payload = $payload;
        $this->memory = $memory;
        $this->workflow = $workflow;
        $this->featureFlags = $featureFlags;
        $this->executionOptions = $executionOptions;
        $this->eventEmitter = $eventEmitter;
        $this->provider = $provider;
        $this->executionId = (string)($payload['executionId'] ?? ('exec-' . bin2hex(random_bytes(4))));
        $this->conversationId = $assistantContext->conversationId;
        $this->assistantId = $assistantContext->assistantId;
        $this->sessionId = $assistantContext->sessionId;
        $this->tenantId = $assistantContext->tenantId;
        $this->userId = $assistantContext->userId;
    }

    public function getAssistantContext(): AssistantContext
    {
        return $this->assistantContext;
    }

    public function getProviderInfo(): ?ProviderInfo
    {
        return $this->providerInfo;
    }

    public function getModelProfile(): ?ModelProfile
    {
        return $this->modelProfile;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getMemory(): array
    {
        return $this->memory;
    }

    public function getWorkflow(): array
    {
        return $this->workflow;
    }

    public function getFeatureFlags(): array
    {
        return $this->featureFlags;
    }

    public function getExecutionOptions(): array
    {
        return $this->executionOptions;
    }

    public function getExecutionId(): string
    {
        return $this->executionId;
    }

    public function getConversationId(): string
    {
        return $this->conversationId;
    }

    public function getAssistantId(): string
    {
        return $this->assistantId;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getProvider(): ?ModelProviderInterface
    {
        return $this->provider;
    }

    public function getConversation(): array
    {
        return $this->conversation;
    }

    public function getAssembledContext(): array
    {
        return $this->assembledContext;
    }

    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function getToolPlan(): array
    {
        return $this->toolPlan;
    }

    public function getToolResults(): array
    {
        return $this->toolResults;
    }

    public function getProviderResponse(): array
    {
        return $this->providerResponse;
    }

    public function getFinalResponse(): string
    {
        return $this->finalResponse;
    }

    public function getReport(): ?PipelineReport
    {
        return $this->report;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setExecutionId(string $executionId): self
    {
        $this->executionId = $executionId;
        return $this;
    }

    public function setConversation(array $conversation): self
    {
        $this->conversation = $conversation;
        return $this;
    }

    public function setAssembledContext(array $assembledContext): self
    {
        $this->assembledContext = $assembledContext;
        return $this;
    }

    public function setPrompt(string $prompt): self
    {
        $this->prompt = $prompt;
        return $this;
    }

    public function setToolPlan(array $toolPlan): self
    {
        $this->toolPlan = $toolPlan;
        return $this;
    }

    public function setToolResults(array $toolResults): self
    {
        $this->toolResults = $toolResults;
        return $this;
    }

    public function setProviderResponse(array $providerResponse): self
    {
        $this->providerResponse = $providerResponse;
        return $this;
    }

    public function setFinalResponse(string $finalResponse): self
    {
        $this->finalResponse = $finalResponse;
        return $this;
    }

    public function setReport(?PipelineReport $report): self
    {
        $this->report = $report;
        return $this;
    }

    public function setProviderInfo(?ProviderInfo $providerInfo): self
    {
        $this->providerInfo = $providerInfo;
        return $this;
    }

    public function setModelProfile(?ModelProfile $modelProfile): self
    {
        $this->modelProfile = $modelProfile;
        return $this;
    }

    public function setProvider(?ModelProviderInterface $provider): self
    {
        $this->provider = $provider;
        return $this;
    }

    public function withProvider(ModelProviderInterface $provider): self
    {
        $clone = clone $this;
        $clone->provider = $provider;
        return $clone;
    }

    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function addMetadata(string $key, $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    public function withProviderInfo(ProviderInfo $providerInfo): self
    {
        $clone = clone $this;
        $clone->providerInfo = $providerInfo;
        return $clone;
    }

    public function withModelProfile(ModelProfile $modelProfile): self
    {
        $clone = clone $this;
        $clone->modelProfile = $modelProfile;
        return $clone;
    }

    public function withPayload(array $payload): self
    {
        $clone = clone $this;
        $clone->payload = $payload;
        return $clone;
    }

    public function withMemory(array $memory): self
    {
        $clone = clone $this;
        $clone->memory = $memory;
        return $clone;
    }

    public function withWorkflow(array $workflow): self
    {
        $clone = clone $this;
        $clone->workflow = $workflow;
        return $clone;
    }

    public function withFeatureFlags(array $featureFlags): self
    {
        $clone = clone $this;
        $clone->featureFlags = $featureFlags;
        return $clone;
    }

    public function withExecutionOptions(array $executionOptions): self
    {
        $clone = clone $this;
        $clone->executionOptions = $executionOptions;
        return $clone;
    }

    public function getEventEmitter(): ?RuntimeEventEmitter
    {
        return $this->eventEmitter;
    }

    public function withEventEmitter(RuntimeEventEmitter $eventEmitter): self
    {
        $clone = clone $this;
        $clone->eventEmitter = $eventEmitter;
        return $clone;
    }
}
