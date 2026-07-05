<?php

class ConversationMetadata
{
    public ?string $conversationId;
    public ?string $assistantId;
    public ?string $userId;
    public string $tenantId;
    public ?string $title;
    public array $tags;
    public string $status;
    public ?string $model;
    public ?string $lastWorkflowId;
    public int $messageCount;
    public int $promptTokens;
    public int $completionTokens;
    public int $totalTokens;
    public float $estimatedCost;
    public int $toolCalls;
    public int $workflowExecutions;
    public array $workflows;
    public array $tools;
    public array $participants;
    public string $createdAt;
    public string $updatedAt;
    public ?string $lastActivity;
    public array $metadata;

    public function __construct(array $data = [])
    {
        $timestamp = $data['updatedAt'] ?? $data['lastActivity'] ?? (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);

        $this->conversationId = $data['conversationId'] ?? null;
        $this->assistantId = $data['assistantId'] ?? null;
        $this->userId = $data['userId'] ?? null;
        $this->tenantId = $data['tenantId'] ?? 'default';
        $this->title = $data['title'] ?? null;
        $this->tags = $data['tags'] ?? [];
        $this->status = $data['status'] ?? 'active';
        $this->model = $data['model'] ?? $data['modelProvider'] ?? null;
        $this->lastWorkflowId = $data['lastWorkflowId'] ?? null;
        $this->messageCount = (int)($data['messageCount'] ?? 0);
        $this->promptTokens = (int)($data['promptTokens'] ?? 0);
        $this->completionTokens = (int)($data['completionTokens'] ?? 0);
        $this->totalTokens = (int)($data['totalTokens'] ?? ($this->promptTokens + $this->completionTokens));
        $this->estimatedCost = (float)($data['estimatedCost'] ?? 0.0);
        $this->toolCalls = (int)($data['toolCalls'] ?? $data['toolInvocations'] ?? 0);
        $this->workflowExecutions = (int)($data['workflowExecutions'] ?? 0);
        $this->workflows = $data['workflows'] ?? [];
        $this->tools = $data['tools'] ?? [];
        $this->participants = $data['participants'] ?? [];
        $this->createdAt = $data['createdAt'] ?? $timestamp;
        $this->updatedAt = $data['updatedAt'] ?? $timestamp;
        $this->lastActivity = $data['lastActivity'] ?? $this->updatedAt;
        $this->metadata = $data['metadata'] ?? [];
    }

    public function toArray(): array
    {
        return [
            'conversationId' => $this->conversationId,
            'assistantId' => $this->assistantId,
            'userId' => $this->userId,
            'tenantId' => $this->tenantId,
            'title' => $this->title,
            'tags' => $this->tags,
            'status' => $this->status,
            'model' => $this->model,
            'lastWorkflowId' => $this->lastWorkflowId,
            'messageCount' => $this->messageCount,
            'promptTokens' => $this->promptTokens,
            'completionTokens' => $this->completionTokens,
            'totalTokens' => $this->totalTokens,
            'estimatedCost' => $this->estimatedCost,
            'toolCalls' => $this->toolCalls,
            'workflowExecutions' => $this->workflowExecutions,
            'workflows' => $this->workflows,
            'tools' => $this->tools,
            'participants' => $this->participants,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'lastActivity' => $this->lastActivity,
            'metadata' => $this->metadata,
        ];
    }

    public function touch(?string $timestamp = null): void
    {
        $timestamp = $timestamp ?? (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        $this->updatedAt = $timestamp;
        $this->lastActivity = $timestamp;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->touch();
    }

    public function addTag(string $tag): void
    {
        if (!in_array($tag, $this->tags, true)) {
            $this->tags[] = $tag;
        }
        $this->touch();
    }

    public function incrementMessageCount(int $delta = 1): void
    {
        $this->messageCount = max(0, $this->messageCount + $delta);
        $this->touch();
    }

    public function recordTokenUsage(int $promptTokens, int $completionTokens, ?string $model = null, float $estimatedCost = 0.0): void
    {
        if ($model !== null) {
            $this->model = $model;
        }
        $this->promptTokens += $promptTokens;
        $this->completionTokens += $completionTokens;
        $this->totalTokens += $promptTokens + $completionTokens;
        $this->estimatedCost += $estimatedCost;
        $this->touch();
    }

    public function recordToolCall(string $toolName): void
    {
        $this->toolCalls++;
        $this->tools[$toolName] = ($this->tools[$toolName] ?? 0) + 1;
        $this->touch();
    }

    public function recordWorkflowExecution(string $workflowId): void
    {
        $this->workflowExecutions++;
        $this->workflows[$workflowId] = ($this->workflows[$workflowId] ?? 0) + 1;
        $this->lastWorkflowId = $workflowId;
        $this->touch();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['closed', 'archived']);
    }
}
