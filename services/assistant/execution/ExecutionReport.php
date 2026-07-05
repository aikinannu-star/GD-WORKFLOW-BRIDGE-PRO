<?php

require_once __DIR__ . '/../context/PipelineReport.php';

class ExecutionReport extends PipelineReport
{
    private string $executionId;
    private ?string $traceId = null;
    private ?string $requestId = null;
    private ?string $tenantId = null;
    private ?string $assistantId = null;
    private ?string $conversationId = null;
    private ?string $workflowId = null;
    private ?string $userId = null;

    private array $provider = [];
    private array $llmUsage = ['promptTokens' => 0, 'completionTokens' => 0, 'totalTokens' => 0, 'estimatedCost' => 0.0, 'currency' => 'USD'];
    private string $usageSource = 'unknown';
    private string $costSource = 'none';
    
    // Analytics: Extended token accounting
    private int $cachedTokens = 0;
    private int $embeddingTokens = 0;
    
    // Analytics: Cost reconciliation
    private ?float $actualCost = null;
    
    // Analytics: Performance metrics
    private ?int $queueTimeMs = null;
    private int $toolCount = 0;
    
    // Analytics: Response characteristics
    private bool $streamed = false;
    private ?string $providerVersion = null;
    private int $retryCount = 0;
    
    private array $tools = [];
    private array $memory = ['reads' => 0, 'writes' => 0, 'vectorQueries' => 0, 'summaries' => 0];
    private array $observability = ['spans' => [], 'metrics' => [], 'events' => []];
    private array $output = ['responseSize' => 0, 'success' => null, 'errorType' => null, 'warnings' => []];

    public function __construct(string $executionId = '')
    {
        parent::__construct('assistantExecution');
        $this->executionId = $executionId ?: uniqid('exec_', true);
        $this->start();
        $this->addMetadata('execution_id', $this->executionId);
    }

    public function getExecutionId(): string
    {
        return $this->executionId;
    }

    public function setTraceId(?string $traceId): self { $this->traceId = $traceId; $this->addMetadata('trace_id', $traceId); return $this; }
    public function setRequestId(?string $requestId): self { $this->requestId = $requestId; $this->addMetadata('request_id', $requestId); return $this; }
    public function setTenantId(?string $tenantId): self { $this->tenantId = $tenantId; $this->addMetadata('tenant_id', $tenantId); return $this; }
    public function setAssistantId(?string $assistantId): self { $this->assistantId = $assistantId; $this->addMetadata('assistant_id', $assistantId); return $this; }
    public function setConversationId(?string $conversationId): self { $this->conversationId = $conversationId; $this->addMetadata('conversation_id', $conversationId); return $this; }
    public function setWorkflowId(?string $workflowId): self { $this->workflowId = $workflowId; $this->addMetadata('workflow_id', $workflowId); return $this; }
    public function setUserId(?string $userId): self { $this->userId = $userId; $this->addMetadata('user_id', $userId); return $this; }

    public function setProviderInfo(array $info): self { $this->provider = $info; $this->addMetadata('provider', $info); return $this; }

    public function addLLMUsage(int $promptTokens, int $completionTokens, float $estimatedCost = 0.0, string $currency = 'USD'): self
    {
        $this->llmUsage['promptTokens'] += $promptTokens;
        $this->llmUsage['completionTokens'] += $completionTokens;
        $this->llmUsage['totalTokens'] = $this->llmUsage['promptTokens'] + $this->llmUsage['completionTokens'];
        $this->llmUsage['estimatedCost'] += $estimatedCost;
        $this->llmUsage['currency'] = $currency;
        $this->addMetadata('llm_usage', $this->llmUsage);
        $this->addMetadata('llm_usage', $this->llmUsage);
        return $this;
    }

    public function setUsageSource(string $source): self { $this->usageSource = $source; $this->addMetadata('usage_source', $source); return $this; }
    public function setCostSource(string $source): self { $this->costSource = $source; $this->addMetadata('cost_source', $source); return $this; }

    public function addToolEvent(array $toolEvent): self
    {
        $this->tools[] = $toolEvent;
        $this->addMetadata('tools', $this->tools);
        return $this;
    }

    public function addMemoryOperation(string $type, int $count = 1): self
    {
        if ($type === 'read') { $this->memory['reads'] += $count; }
        if ($type === 'write') { $this->memory['writes'] += $count; }
        if ($type === 'vector') { $this->memory['vectorQueries'] += $count; }
        if ($type === 'summary') { $this->memory['summaries'] += $count; }
        $this->addMetadata('memory', $this->memory);
        return $this;
    }

    public function addSpan(array $span): self { $this->observability['spans'][] = $span; $this->addMetadata('spans', $this->observability['spans']); return $this; }
    public function addMetric(array $metric): self { $this->observability['metrics'][] = $metric; $this->addMetadata('metrics', $this->observability['metrics']); return $this; }
    public function addEvent(array $event): self { $this->observability['events'][] = $event; $this->addMetadata('events', $this->observability['events']); return $this; }

    public function setOutput(array $output): self { $this->output = array_merge($this->output, $output); $this->addMetadata('output', $this->output); return $this; }

    public function markFailure(string $reason, ?string $errorType = null): self
    {
        $this->output['success'] = false;
        $this->output['errorType'] = $errorType ?: 'failure';
        $this->output['warnings'][] = $reason;
        $this->addMetadata('output', $this->output);
        $this->addMetadata('failure_reason', $reason);
        $this->addMetadata('error_type', $this->output['errorType']);
        return $this;
    }

    // Analytics: Token accounting
    public function addCachedTokens(int $count): self { $this->cachedTokens += $count; $this->addMetadata('cached_tokens', $this->cachedTokens); return $this; }
    public function getCachedTokens(): int { return $this->cachedTokens; }
    public function setCachedTokens(int $count): self { $this->cachedTokens = $count; $this->addMetadata('cached_tokens', $this->cachedTokens); return $this; }

    public function addEmbeddingTokens(int $count): self { $this->embeddingTokens += $count; $this->addMetadata('embedding_tokens', $this->embeddingTokens); return $this; }
    public function getEmbeddingTokens(): int { return $this->embeddingTokens; }
    public function setEmbeddingTokens(int $count): self { $this->embeddingTokens = $count; $this->addMetadata('embedding_tokens', $this->embeddingTokens); return $this; }

    // Analytics: Cost reconciliation
    public function setActualCost(?float $cost): self { $this->actualCost = $cost !== null ? (float)$cost : null; $this->addMetadata('actual_cost', $this->actualCost); return $this; }
    public function getActualCost(): ?float { return $this->actualCost; }

    // Analytics: Performance metrics
    public function setQueueTimeMs(?int $ms): self { $this->queueTimeMs = $ms; $this->addMetadata('queue_time_ms', $this->queueTimeMs); return $this; }
    public function getQueueTimeMs(): ?int { return $this->queueTimeMs; }

    public function setToolCount(int $count): self { $this->toolCount = $count; $this->addMetadata('tool_count', $this->toolCount); return $this; }
    public function getToolCount(): int { return $this->toolCount; }

    // Analytics: Response characteristics
    public function setStreamed(bool $streamed): self { $this->streamed = $streamed; $this->addMetadata('streamed', $this->streamed); return $this; }
    public function isStreamed(): bool { return $this->streamed; }

    public function setProviderVersion(?string $version): self { $this->providerVersion = $version; $this->addMetadata('provider_version', $this->providerVersion); return $this; }
    public function getProviderVersion(): ?string { return $this->providerVersion; }

    public function setRetryCount(int $count): self { $this->retryCount = $count; $this->addMetadata('retry_count', $this->retryCount); return $this; }
    public function getRetryCount(): int { return $this->retryCount; }
    public function incrementRetryCount(): self { $this->retryCount++; $this->addMetadata('retry_count', $this->retryCount); return $this; }

    public function toArray(): array
    {
        $base = parent::toArray();
        $base['executionId'] = $this->executionId;
        $base['traceId'] = $this->traceId;
        $base['requestId'] = $this->requestId;
        $base['tenantId'] = $this->tenantId;
        $base['assistantId'] = $this->assistantId;
        $base['conversationId'] = $this->conversationId;
        $base['workflowId'] = $this->workflowId;
        $base['userId'] = $this->userId;
        $base['provider'] = $this->provider;
        $base['llmUsage'] = $this->llmUsage;
        $base['usageSource'] = $this->usageSource;
        $base['costSource'] = $this->costSource;
        $base['cachedTokens'] = $this->cachedTokens;
        $base['embeddingTokens'] = $this->embeddingTokens;
        $base['actualCost'] = $this->actualCost;
        $base['queueTimeMs'] = $this->queueTimeMs;
        $base['toolCount'] = $this->toolCount;
        $base['streamed'] = $this->streamed;
        $base['providerVersion'] = $this->providerVersion;
        $base['retryCount'] = $this->retryCount;
        $base['tools'] = $this->tools;
        $base['memory'] = $this->memory;
        $base['observability'] = $this->observability;
        $base['output'] = $this->output;
        return $base;
    }
}
