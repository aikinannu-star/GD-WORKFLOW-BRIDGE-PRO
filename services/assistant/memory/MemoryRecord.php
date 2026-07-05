<?php

class MemoryRecord
{
    public ?string $id;
    public string $tenantId;
    public string $userId;
    public ?string $conversationId;
    public string $type;
    public string $content;
    public float $confidence;
    public array $tags;
    public array $sourceMessages;
    public string $createdAt;
    public string $lastConfirmedAt;
    public ?string $expiresAt;
    public array $metadata;

    public function __construct(array $data = [])
    {
        $timestamp = $data['createdAt'] ?? (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        $this->id = $data['id'] ?? null;
        $this->tenantId = $data['tenantId'] ?? 'default';
        $this->userId = $data['userId'] ?? '';
        $this->conversationId = $data['conversationId'] ?? null;
        $this->type = $data['type'] ?? 'fact';
        $this->content = $data['content'] ?? '';
        $this->confidence = (float)($data['confidence'] ?? 0.0);
        $this->tags = $data['tags'] ?? [];
        $this->sourceMessages = $data['sourceMessages'] ?? [];
        $this->createdAt = $data['createdAt'] ?? $timestamp;
        $this->lastConfirmedAt = $data['lastConfirmedAt'] ?? $timestamp;
        $this->expiresAt = $data['expiresAt'] ?? null;
        $this->metadata = $data['metadata'] ?? [];
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenantId' => $this->tenantId,
            'userId' => $this->userId,
            'conversationId' => $this->conversationId,
            'type' => $this->type,
            'content' => $this->content,
            'confidence' => $this->confidence,
            'tags' => $this->tags,
            'sourceMessages' => $this->sourceMessages,
            'createdAt' => $this->createdAt,
            'lastConfirmedAt' => $this->lastConfirmedAt,
            'expiresAt' => $this->expiresAt,
            'metadata' => $this->metadata,
        ];
    }

    public function isExpired(?string $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $now = $now ?? (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        return $now > $this->expiresAt;
    }

    public function touchUsage(?string $timestamp = null): void
    {
        $this->lastConfirmedAt = $timestamp ?? (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        $this->metadata['lastUsedAt'] = $this->lastConfirmedAt;
    }

    public function decayConfidence(float $factor = 0.1): void
    {
        $this->confidence = max(0.0, $this->confidence - $factor);
        $this->metadata['confidenceDecayedAt'] = (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
    }

    public function archive(?string $timestamp = null): void
    {
        $this->metadata['status'] = 'archived';
        $this->metadata['archivedAt'] = $timestamp ?? (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
    }

    public function supersede(string $supersededById, ?string $timestamp = null): void
    {
        $this->metadata['status'] = 'superseded';
        $this->metadata['supersededById'] = $supersededById;
        $this->metadata['supersededAt'] = $timestamp ?? (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
    }
}
